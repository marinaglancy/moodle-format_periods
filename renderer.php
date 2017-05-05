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
 * Renderer for outputting the periods course format.
 *
 * @package format_periods
 * @copyright 2014 Marina Glancy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/format/renderer.php');
require_once($CFG->dirroot.'/course/format/periods/lib.php');


/**
 * Basic renderer for periods format.
 *
 * @copyright 2014 Marina Glancy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_periods_renderer extends format_section_renderer_base {
    /**
     * Generate the starting container html for a list of sections
     * @return string HTML to output.
     */
    protected function start_section_list() {
        return html_writer::start_tag('ul', array('class' => 'periods'));
    }

    /**
     * Generate the closing container html for a list of sections
     * @return string HTML to output.
     */
    protected function end_section_list() {
        return html_writer::end_tag('ul');
    }

    /**
     * Generate the title for this section page
     * @return string the page title
     */
    protected function page_title() {
        return get_string('weeklyoutline');
    }

    /**
     * Output the html for a multiple section page
     *
     * @param stdClass $course The course entry from DB
     * @param array $sections (argument not used)
     * @param array $mods (argument not used)
     * @param array $modnames (argument not used)
     * @param array $modnamesused (argument not used)
     */
    public function print_multiple_section_page($course, $sections, $mods, $modnames, $modnamesused) {
        global $PAGE;

        $modinfo = get_fast_modinfo($course);
        $course = course_get_format($course)->get_course();

        $context = context_course::instance($course->id);
        // Title with completion help icon.
        $completioninfo = new completion_info($course);
        echo $completioninfo->display_help_icon();
        echo $this->output->heading($this->page_title(), 2, 'accesshide');

        // Copy activity clipboard..
        echo $this->course_activity_clipboard($course, 0);

        // Now the list of sections..
        echo $this->start_section_list();

        foreach ($modinfo->get_section_info_all() as $section => $thissection) {
            if ($section == 0) {
                // 0-section is displayed a little different then the others.
                if ($thissection->summary or !empty($modinfo->sections[0]) or $PAGE->user_is_editing()) {
                    echo $this->section_header($thissection, $course, false, 0);
                    echo $this->courserenderer->course_section_cm_list($course, $thissection, 0);
                    echo $this->courserenderer->course_section_add_cm_control($course, 0, 0);
                    echo $this->section_footer();
                }
                continue;
            }

            // Do not display sections in the past/future that must be hidden by course settings.
            $displaymode = course_get_format($course)->get_section_display_mode($thissection);
            if (!has_capability('moodle/course:viewhiddenactivities', $context)) {
                if ($displaymode == FORMAT_PERIODS_NOTAVAILABLE) {
                    echo $this->section_hidden($section, $course->id);
                    continue;
                }
                if ($displaymode == FORMAT_PERIODS_NOTDISPLAYED || $displaymode == FORMAT_PERIODS_HIDDEN) {
                    continue;
                }
            }

            // Show the section if the user is permitted to access it, OR if it's not available
            // but there is some available info text which explains the reason & should display.
            $showsection = $thissection->uservisible ||
                    ($thissection->visible && !$thissection->available &&
                    !empty($thissection->availableinfo));
            if (!$showsection) {
                // If the hiddensections option is set to 'show hidden sections in collapsed
                // form', then display the hidden section message - UNLESS the section is
                // hidden by the availability system, which is set to hide the reason.
                if (!$course->hiddensections && $thissection->available) {
                    echo $this->section_hidden($section, $course->id);
                }

                continue;
            }

            if (!$PAGE->user_is_editing() &&
                    $displaymode == FORMAT_PERIODS_COLLAPSED) {
                // Display section summary only.
                echo $this->section_summary($thissection, $course, null);
            } else {
                echo $this->section_header($thissection, $course, false, 0);
                if ($thissection->uservisible) {
                    echo $this->courserenderer->course_section_cm_list($course, $thissection, 0);
                    echo $this->courserenderer->course_section_add_cm_control($course, $section, 0);
                }
                echo $this->section_footer();
            }
        }

        echo $this->end_section_list();

        if ($PAGE->user_is_editing() and has_capability('moodle/course:update', $context)) {
            echo $this->change_number_sections($course, 0);
        }

    }

    /**
     * Returns sections dates inteval as a human-readable string
     *
     * @param int|stdClass $section either section number (field course_section.section) or row from course_section table
     * @return string
     */
    protected function section_dates($section) {
        $courseformat = course_get_format($section->course);
        $section = $courseformat->get_section($section);
        $context = context_course::instance($section->course);
        if (has_capability('moodle/course:update', $context)) {
            $defaultduration = $courseformat->get_course()->periodduration;
            $o = array(
                'dates' => $courseformat->get_default_section_name($section),
                'duration' => $section->periodduration ? $section->periodduration : $defaultduration
            );
            $o['duration'] = $this->duration_to_string($o['duration']);
            if (!empty($section->name)) {
                if (!empty($section->periodduration) && $section->periodduration != $defaultduration) {
                    $string = 'sectiondatesduration';
                } else {
                    $string = 'sectiondates';
                }
            } else if ($section->periodduration && $section->periodduration != $defaultduration) {
                $string = 'sectionduration';
            } else {
                return '';
            }
            $text = get_string($string, 'format_periods', (object)$o);
            return html_writer::tag('div', $text, array('class' => 'sectiondates'));
        }
        return '';
    }

    /**
     * Converts a duration (in the format 'NN UNIT') into a localised language string
     * (e.g. '4 week' => '4 Wochen')
     *
     * @param string $duration
     * @return string
     */
    protected function duration_to_string($duration) {
        if (!preg_match('/^(\d+) (\w+)$/', $duration, $matches)) {
             return $duration;
        }
        $num = (int)$matches[1];
        $units = $matches[2];
        if ($num > 1) {
            $units .= 's';
        }
        return get_string('num'.$units, 'core', $num);
    }

    /**
     * Generate html for a section summary text
     *
     * @param stdClass $section The course_section entry from DB
     * @return string HTML to output.
     */
    protected function format_summary_text($section) {
        $context = context_course::instance($section->course);
        $summarytext = $this->section_dates($section). file_rewrite_pluginfile_urls($section->summary, 'pluginfile.php',
            $context->id, 'course', 'section', $section->id);

        $options = new stdClass();
        $options->noclean = true;
        $options->overflowdiv = true;
        return format_text($summarytext, $section->summaryformat, $options);
    }

    /**
     * Generate the section title, wraps it in a link to the section page if page is to be displayed on a separate page
     *
     * @param stdClass $section The course_section entry from DB
     * @param stdClass $course The course entry from DB
     * @return string HTML to output.
     */
    public function section_title($section, $course) {
        global $CFG;
        if ((float)$CFG->version >= 2016052300) {
            // For Moodle 3.1 and later use inplace editable section name.
            return $this->render(course_get_format($course)->inplace_editable_render_section_name($section));
        }
        return parent::section_title($section, $course);
    }

    /**
     * Generate the section title to be displayed on the section page, without a link
     *
     * This method is only invoked in Moodle versions 3.1 and later.
     *
     * @param stdClass $section The course_section entry from DB
     * @param stdClass $course The course entry from DB
     * @return string HTML to output.
     */
    public function section_title_without_link($section, $course) {
        return $this->render(course_get_format($course)->inplace_editable_render_section_name($section, false));
    }
}
