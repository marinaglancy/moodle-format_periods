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
 * Upgrade scripts for course format "periods"
 *
 * @package    format_periods
 * @copyright  2017 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * This method finds all courses in 'periods' format that have actual number of sections
 * bigger than their 'numsections' course format option.
 * For each such course we call {@link format_periods_upgrade_hide_extra_sections()} and
 * either delete or hide "orphaned" sections.
 */
function format_periods_upgrade_remove_numsections() {
    global $DB;

    $sql1 = "SELECT c.id, max(cs.section) AS sectionsactual
          FROM {course} c
          JOIN {course_sections} cs ON cs.course = c.id
          WHERE c.format = :format1
          GROUP BY c.id";

    $sql2 = "SELECT c.id, n.value AS numsections
          FROM {course} c
          JOIN {course_format_options} n ON n.courseid = c.id AND n.format = :format1 AND n.name = :numsections AND n.sectionid = 0
          WHERE c.format = :format2";

    $params = ['format1' => 'periods', 'format2' => 'periods', 'numsections' => 'numsections'];

    $actual = $DB->get_records_sql_menu($sql1, $params);
    $numsections = $DB->get_records_sql_menu($sql2, $params);
    $needfixing = [];

    $defaultnumsections = get_config('moodlecourse', 'numsections');

    foreach ($actual as $courseid => $sectionsactual) {
        if (array_key_exists($courseid, $numsections)) {
            $n = (int)$numsections[$courseid];
        } else {
            $n = $defaultnumsections;
        }
        if ($sectionsactual > $n) {
            $needfixing[$courseid] = $n;
        }
    }
    unset($actual);
    unset($numsections);

    foreach ($needfixing as $courseid => $numsections) {
        format_periods_upgrade_hide_extra_sections($courseid, $numsections);
    }

    $DB->delete_records('course_format_options', ['format' => 'periods', 'sectionid' => 0, 'name' => 'numsections']);
}

/**
 * Find all sections in the course with sectionnum bigger than numsections.
 * Either delete these sections or hide them
 *
 * We will only delete a section if it is completely empty and all sections below
 * it are also empty
 *
 * @param int $courseid
 * @param int $numsections
 */
function format_periods_upgrade_hide_extra_sections($courseid, $numsections) {
    global $DB;
    $sections = $DB->get_records_sql('SELECT id, name, summary, sequence, visible
        FROM {course_sections}
        WHERE course = ? AND section > ?
        ORDER BY section DESC', [$courseid, $numsections]);
    $candelete = true;
    $tohide = [];
    $todelete = [];
    foreach ($sections as $section) {
        if ($candelete && (!empty($section->summary) || !empty($section->sequence) || !empty($section->name))) {
            $candelete = false;
        }
        if ($candelete) {
            $todelete[] = $section->id;
        } else if ($section->visible) {
            $tohide[] = $section->id;
        }
    }
    if ($todelete) {
        // Delete empty sections in the end.
        // This is an upgrade script - no events or cache resets are needed.
        // We also know that these sections do not have any modules so it is safe to just delete records in the table.
        $DB->delete_records_list('course_sections', 'id', $todelete);
    }
    if ($tohide) {
        // Hide other orphaned sections.
        // This is different from what set_section_visible() does but we want to preserve actual
        // module visibility in this case.
        list($sql, $params) = $DB->get_in_or_equal($tohide);
        $DB->execute("UPDATE {course_sections} SET visible = 0 WHERE id " . $sql, $params);
    }
}

/**
 * Set 'automaticenddate' for existing courses.
 */
function format_periods_upgrade_automaticenddate() {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/format/periods/lib.php');

    // Go through the existing courses using the periods format with no value set for the 'automaticenddate'.
    $sql = "SELECT c.id, c.enddate, cfo.id as cfoid
                  FROM {course} c
             LEFT JOIN {course_format_options} cfo
                    ON cfo.courseid = c.id
                   AND cfo.format = c.format
                   AND cfo.name = :optionname
                   AND cfo.sectionid = 0
                 WHERE c.format = :format
                   AND cfo.id IS NULL";
    $params = ['optionname' => 'automaticenddate', 'format' => 'periods'];
    $courses = $DB->get_recordset_sql($sql, $params);
    foreach ($courses as $course) {
        $option = new stdClass();
        $option->courseid = $course->id;
        $option->format = 'periods';
        $option->sectionid = 0;
        $option->name = 'automaticenddate';
        if (empty($course->enddate)) {
            $option->value = 1;
            $DB->insert_record('course_format_options', $option);

            // Now, let's update the course end date.
            format_periods::update_end_date($course->id);
        } else {
            $option->value = 0;
            $DB->insert_record('course_format_options', $option);
        }
    }
    $courses->close();

}