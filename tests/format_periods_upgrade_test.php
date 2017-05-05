<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * format_periods unit tests for upgradelib
 *
 * @package    format_periods
 * @copyright  2017 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/periods/db/upgradelib.php');

/**
 * format_periods unit tests for upgradelib
 *
 * @package    format_periods
 * @copyright  2017 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_periods_upgrade_testcase extends advanced_testcase {

    /**
     * Test upgrade step to remove orphaned sections.
     */
    public function test_numsections_no_actions() {
        global $DB;

        $this->resetAfterTest(true);

        $params = array('format' => 'periods', 'numsections' => 5, 'startdate' => 1445644800);
        $course = $this->getDataGenerator()->create_course($params);
        // This test is executed after 'numsections' option was already removed, add it manually.
        $DB->insert_record('course_format_options', ['courseid' => $course->id, 'format' => 'periods',
            'sectionid' => 0, 'name' => 'numsections', 'value' => '5']);

        // There are 6 sections in the course (0-section and sections 1, ... 5).
        $this->assertEquals(6, $DB->count_records('course_sections', ['course' => $course->id]));

        format_periods_upgrade_remove_numsections();

        // There are still 6 sections in the course.
        $this->assertEquals(6, $DB->count_records('course_sections', ['course' => $course->id]));

    }

    /**
     * Test upgrade step to remove orphaned sections.
     */
    public function test_numsections_delete_empty() {
        global $DB;

        $this->resetAfterTest(true);

        // Set default number of sections to 10.
        set_config('numsections', 10, 'moodlecourse');

        $params1 = array('format' => 'periods', 'numsections' => 5, 'startdate' => 1445644800);
        $course1 = $this->getDataGenerator()->create_course($params1);
        $params2 = array('format' => 'periods', 'numsections' => 20, 'startdate' => 1445644800);
        $course2 = $this->getDataGenerator()->create_course($params2);
        // This test is executed after 'numsections' option was already removed, add it manually and
        // set it to be 2 less than actual number of sections.
        $DB->insert_record('course_format_options', ['courseid' => $course1->id, 'format' => 'periods',
            'sectionid' => 0, 'name' => 'numsections', 'value' => '3']);

        // There are 6 sections in the first course (0-section and sections 1, ... 5).
        $this->assertEquals(6, $DB->count_records('course_sections', ['course' => $course1->id]));
        // There are 21 sections in the second course.
        $this->assertEquals(21, $DB->count_records('course_sections', ['course' => $course2->id]));

        format_periods_upgrade_remove_numsections();

        // Two sections were deleted in the first course.
        $this->assertEquals(4, $DB->count_records('course_sections', ['course' => $course1->id]));
        // The second course was reset to 11 sections (default plus 0-section).
        $this->assertEquals(11, $DB->count_records('course_sections', ['course' => $course2->id]));

    }

    /**
     * Test upgrade step to remove orphaned sections.
     */
    public function test_numsections_hide_non_empty() {
        global $DB;

        $this->resetAfterTest(true);

        $params = array('format' => 'periods', 'numsections' => 5, 'startdate' => 1445644800);
        $course = $this->getDataGenerator()->create_course($params);

        // Add a module to the second last section.
        $cm = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'section' => 4]);

        // This test is executed after 'numsections' option was already removed, add it manually and
        // set it to be 2 less than actual number of sections.
        $DB->insert_record('course_format_options', ['courseid' => $course->id, 'format' => 'periods',
            'sectionid' => 0, 'name' => 'numsections', 'value' => '3']);

        // There are 6 sections.
        $this->assertEquals(6, $DB->count_records('course_sections', ['course' => $course->id]));

        format_periods_upgrade_remove_numsections();

        // One section was deleted and one hidden.
        $this->assertEquals(5, $DB->count_records('course_sections', ['course' => $course->id]));
        $this->assertEquals(0, $DB->get_field('course_sections', 'visible', ['course' => $course->id, 'section' => 4]));
        // The module is still visible.
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $cm->cmid]));
    }

    public function test_upgrade_automaticenddate() {
        global $DB;

        $this->resetAfterTest(true);

        $params = array('format' => 'periods', 'numsections' => 5, 'startdate' => 1445644800);
        $course1 = $this->getDataGenerator()->create_course($params);
        $course2 = $this->getDataGenerator()->create_course($params);

        // Remove the option to pretend we are on 3.2.
        $DB->delete_records('course_format_options', ['name' => 'automaticenddate', 'format' => 'periods']);

        // Set end date to something in course1 and to 0 in course2. Perform upgrade.
        $DB->set_field('course', 'enddate', $params['startdate'] + YEARSECS, ['id' => $course1->id]);
        $DB->set_field('course', 'enddate', 0, ['id' => $course2->id]);
        format_periods_upgrade_automaticenddate();

        // For course1 (with enddate) automaticenddate is 0 and enddate is preserved.
        $courseformat1 = course_get_format($course1->id);
        $this->assertEquals(0, $courseformat1->get_course()->automaticenddate);
        $this->assertEquals($params['startdate'] + YEARSECS, $courseformat1->get_course()->enddate);

        // For course2 (without enddate) automaticenddate is 1 and enddate is calculated.
        $courseformat2 = course_get_format($course2->id);
        $this->assertEquals(1, $courseformat2->get_course()->automaticenddate);
        $this->assertEquals($params['startdate'] + 5 * WEEKSECS, $courseformat2->get_course()->enddate);
    }
}
