<?php

/**
 * This file contains main class for the course format Periods
 *
 * @package   format_periods
 * @copyright 2014 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot. '/course/format/lib.php');

/*
define('FORMAT_PERIODS_FUTURE_AVAILABLE_ALWAYS', 0);
define('FORMAT_PERIODS_FUTURE_COLLAPSED', 1);
define('FORMAT_PERIODS_FUTURE_NOT_AVAILABLE', 5);
define('FORMAT_PERIODS_FUTURE_NOT_AVAILABLE_WITH_INFO', 6);

define('FORMAT_PERIODS_PAST_DISPLAYED_ALWAYS', 0);
define('FORMAT_PERIODS_PAST_COLLAPSED', 1);
define('FORMAT_PERIODS_PAST_NOT_DISPLAYED', 2);

define('FORMAT_PERIODS_PAST_COMPLETED_AS_ABOVE', 0);
*/

define('FORMAT_PERIODS_AS_ABOVE', 0);

define('FORMAT_PERIODS_EXPANDED', 0);
define('FORMAT_PERIODS_COLLAPSED', 1);
define('FORMAT_PERIODS_NOTDISPLAYED', 2);
define('FORMAT_PERIODS_HIDDEN', 5);
define('FORMAT_PERIODS_NOTAVAILABLE', 6);

/* UPGRADE SCRIPT: future not available 5->2 */

/**
 * Main class for the Periods course format
 *
 * @package    format_periods
 * @copyright 2014 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_periods extends format_base {

    /**
     * Returns true if this course format uses sections
     *
     * @return bool
     */
    public function uses_sections() {
        return true;
    }

    /**
     * Returns the display name of the given section that the course prefers.
     *
     * @param int|stdClass $section Section object from database or just field section.section
     * @return string Display name that the course format prefers, e.g. "Topic 2"
     */
    public function get_section_name($section) {
        $section = $this->get_section($section);
        if ((string)$section->name !== '') {
            // Return the name the user set.
            return format_string($section->name, true, array('context' => context_course::instance($this->courseid)));
        } else if ($section->section == 0) {
            // Return the general section.
            return get_string('section0name', 'format_periods');
        } else {
            $dates = $this->get_section_dates($section);

            // We subtract 24 hours for display purposes.
            $dates->end = ($dates->end - 86400);

            $dateformat = get_string('strftimedateshort');
            $weekday = userdate($dates->start, $dateformat);
            $endweekday = userdate($dates->end, $dateformat);
            if ($weekday === $endweekday) {
                return $weekday;
            } else {
                return $weekday.' - '.$endweekday;
            }
        }
    }

    /**
     * The URL to use for the specified course (with section)
     *
     * @param int|stdClass $section Section object from database or just field course_sections.section
     *     if omitted the course view page is returned
     * @param array $options options for view URL. At the moment core uses:
     *     'navigation' (bool) if true and section has no separate page, the function returns null
     *     'sr' (int) used by multipage formats to specify to which section to return
     * @return null|moodle_url
     */
    public function get_view_url($section, $options = array()) {
        $course = $this->get_course();
        $url = new moodle_url('/course/view.php', array('id' => $course->id));

        $sr = null;
        if (array_key_exists('sr', $options)) {
            $sr = $options['sr'];
        }
        if (is_object($section)) {
            $sectionno = $section->section;
        } else {
            $sectionno = $section;
        }
        if ($sectionno !== null) {
            if ($sr !== null) {
                if ($sr) {
                    $displaymode = COURSE_DISPLAY_MULTIPAGE;
                    $sectionno = $sr;
                } else {
                    $displaymode = COURSE_DISPLAY_SINGLEPAGE;
                }
            } else {
                $displaymode = $this->get_section_display_mode($section);
            }
            if ($sectionno != 0 && $displaymode == COURSE_DISPLAY_MULTIPAGE) {
                $url->param('section', $sectionno);
            } else {
                if (!empty($options['navigation'])) {
                    return null;
                }
                $url->set_anchor('section-'.$sectionno);
            }
        }
        return $url;
    }

    /**
     * Returns the information about the ajax support in the given source format
     *
     * The returned object's property (boolean)capable indicates that
     * the course format supports Moodle course ajax features.
     *
     * @return stdClass
     */
    public function supports_ajax() {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    /**
     * Loads all of the course sections into the navigation
     *
     * @param global_navigation $navigation
     * @param navigation_node $node The course node within the navigation
     */
    public function extend_course_navigation($navigation, navigation_node $node) {
        global $PAGE;
        // if section is specified in course/view.php, make sure it is expanded in navigation
        if ($navigation->includesectionnum === false) {
            $selectedsection = optional_param('section', null, PARAM_INT);
            if ($selectedsection !== null && (!defined('AJAX_SCRIPT') || AJAX_SCRIPT == '0') &&
                    $PAGE->url->compare(new moodle_url('/course/view.php'), URL_MATCH_BASE)) {
                $navigation->includesectionnum = $selectedsection;
            }
        }
        parent::extend_course_navigation($navigation, $node);

        $modinfo = get_fast_modinfo($this->get_course());
        $context = context_course::instance($modinfo->courseid);
        $sectioninfos = $this->get_sections();

        foreach ($sectioninfos as $sectionnum => $section) {
            if ($sectionnum == 0) {
                if (empty($modinfo->sections[0]) && ($sectionnode = $node->get($section->id, navigation_node::TYPE_SECTION))) {
                    // The general section is empty, remove the node from navigation.
                    $sectionnode->remove();
                }
            } else if (($this->get_section_display_mode($section) > FORMAT_PERIODS_COLLAPSED) &&
                    ($sectionnode = $node->get($section->id, navigation_node::TYPE_SECTION))) {
                // Remove or hide navigation nodes for sections that are hidden/not available.
                if (!has_capability('moodle/course:viewhiddenactivities', $context) &&
                        $navigation->includesectionnum != $sectionnum) {
                    $sectionnode->remove();
                } else {
                    $sectionnode->hidden = true;
                }
            }
        }
    }

    /**
     * Custom action after section has been moved in AJAX mode
     *
     * Used in course/rest.php
     *
     * @return array This will be passed in ajax respose
     */
    function ajax_section_move() {
        global $PAGE;
        $titles = array();
        $current = -1;
        $course = $this->get_course();
        $modinfo = get_fast_modinfo($course);
        $renderer = $this->get_renderer($PAGE);
        if ($renderer && ($sections = $modinfo->get_section_info_all())) {
            foreach ($sections as $number => $section) {
                $titles[$number] = $renderer->section_title($section, $course);
                if ($this->is_section_current($section)) {
                    $current = $number;
                }
            }
        }
        return array('sectiontitles' => $titles, 'current' => $current, 'action' => 'move');
    }

    /**
     * Returns the list of blocks to be automatically added for the newly created course
     *
     * @return array of default blocks, must contain two keys BLOCK_POS_LEFT and BLOCK_POS_RIGHT
     *     each of values is an array of block names (for left and right side columns)
     */
    public function get_default_blocks() {
        return array(
            BLOCK_POS_LEFT => array(),
            BLOCK_POS_RIGHT => array('search_forums', 'news_items', 'calendar_upcoming', 'recent_activity')
        );
    }

    /**
     * Definitions of the additional options that this course format uses for course
     *
     * Periods format uses the following options:
     * - coursedisplay
     * - numsections
     * - hiddensections
     *
     * @param bool $foreditform
     * @return array of options
     */
    public function course_format_options($foreditform = false) {
        global $CFG;
        static $courseformatoptions = false;
        if ($courseformatoptions === false) {
            $courseconfig = get_config('moodlecourse');
            $courseformatoptions = array(
                'periodduration' => array(
                    'default' => '1 week', // TODO this does not work.
                    'type' => PARAM_NOTAGS
                ),
                'numsections' => array(
                    'default' => $courseconfig->numsections,
                    'type' => PARAM_INT,
                ),
                'hiddensections' => array(
                    'default' => $courseconfig->hiddensections,
                    'type' => PARAM_INT,
                ),
                'coursedisplay' => array(
                    'default' => $courseconfig->coursedisplay,
                    'type' => PARAM_INT,
                ),
                'showfutureperiods' => array(
                    'default' => 0,
                    'type' => PARAM_INT
                ),
                'futuresneakpeek' => array(
                    'default' => 0,
                    'type' => PARAM_INT
                ),
                'showpastperiods' => array(
                    'default' => 0,
                    'type' => PARAM_INT
                ),
                'showpastcompleted' => array(
                    'default' => 0,
                    'type' => PARAM_INT
                )
            );
        }
        if ($foreditform && !isset($courseformatoptions['coursedisplay']['label'])) {

            require_once("$CFG->dirroot/course/format/periods/periodduration.php");

            $courseconfig = get_config('moodlecourse');
            $sectionmenu = array();
            $max = $courseconfig->maxsections;
            if (!isset($max) || !is_numeric($max)) {
                $max = 52;
            }
            for ($i = 0; $i <= $max; $i++) {
                $sectionmenu[$i] = "$i";
            }
            $courseformatoptionsedit = array(
                'periodduration' => array(
                    'label' => new lang_string('periodduration', 'format_periods'),
                    'help' => 'periodduration',
                    'help_component' => 'format_periods',
                    'element_type' => 'periodduration',
                ),
                'numsections' => array(
                    'label' => new lang_string('numberperiods', 'format_periods'),
                    'element_type' => 'select',
                    'element_attributes' => array($sectionmenu),
                ),
                'hiddensections' => array(
                    'label' => new lang_string('hiddensections'),
                    'help' => 'hiddensections',
                    'help_component' => 'moodle',
                    'element_type' => 'select',
                    'element_attributes' => array(
                        array(
                            0 => new lang_string('hiddensectionscollapsed'),
                            1 => new lang_string('hiddensectionsinvisible')
                        )
                    ),
                ),
                'coursedisplay' => array(
                    'label' => new lang_string('showperiods', 'format_periods'),
                    'element_type' => 'select',
                    'element_attributes' => array(
                        array(
                            FORMAT_PERIODS_EXPANDED => get_string('showexpanded', 'format_periods'),
                            FORMAT_PERIODS_COLLAPSED => get_string('showcollapsed', 'format_periods'),
                        )
                    ),
                    'help' => 'showperiods',
                    'help_component' => 'format_periods',
                ),
                'showfutureperiods' => array(
                    'label' => new lang_string('showfutureperiods', 'format_periods'),
                    'help' => 'showfutureperiods',
                    'help_component' => 'format_periods',
                    'element_type' => 'select',
                    'element_attributes' => array(
                        array(
                            FORMAT_PERIODS_AS_ABOVE => get_string('sameascurrent', 'format_periods'),
                            FORMAT_PERIODS_COLLAPSED => get_string('showcollapsed', 'format_periods'),
                            FORMAT_PERIODS_NOTAVAILABLE => get_string('shownotavailable', 'format_periods'),
                            FORMAT_PERIODS_HIDDEN => get_string('hidecompletely', 'format_periods'),
                        )
                    ),
                ),
                'futuresneakpeek' => array(
                    'label' => new lang_string('futuresneakpeek', 'format_periods'),
                    'help' => 'futuresneakpeek',
                    'help_component' => 'format_periods',
                    'element_type' => 'duration',
                    'element_attributes' => array(
                        array('defaultunit' => 86400, 'optional' => false)
                    )
                ),
                'showpastperiods' => array(
                    'label' => new lang_string('showpastperiods', 'format_periods'),
                    'help' => 'showpastperiods',
                    'help_component' => 'format_periods',
                    'element_type' => 'select',
                    'element_attributes' => array(
                        array(
                            FORMAT_PERIODS_AS_ABOVE => get_string('sameascurrent', 'format_periods'),
                            FORMAT_PERIODS_COLLAPSED => get_string('showcollapsed', 'format_periods'),
                            FORMAT_PERIODS_NOTDISPLAYED => get_string('hidefromcourseview', 'format_periods'),
                            FORMAT_PERIODS_HIDDEN => get_string('hidecompletely', 'format_periods'),
                        )
                    ),
                ),
                'showpastcompleted' => array(
                    'label' => new lang_string('showpastcompleted', 'format_periods'),
                    'help' => 'showpastcompleted',
                    'help_component' => 'format_periods',
                    'element_type' => 'select',
                    'element_attributes' => array(
                        array(
                            FORMAT_PERIODS_AS_ABOVE => get_string('sameaspast', 'format_periods'),
                            FORMAT_PERIODS_COLLAPSED => get_string('showcollapsed', 'format_periods'),
                            FORMAT_PERIODS_NOTDISPLAYED => get_string('hidefromcourseview', 'format_periods'),
                            FORMAT_PERIODS_HIDDEN => get_string('hidecompletely', 'format_periods'),
                        )
                    ),
                )
            );
            $courseformatoptions = array_merge_recursive($courseformatoptions, $courseformatoptionsedit);
        }
        return $courseformatoptions;
    }

    /**
     * Adds format options elements to the course/section edit form.
     *
     * This function is called from {@link course_edit_form::definition_after_data()}.
     *
     * @param MoodleQuickForm $mform form the elements are added to.
     * @param bool $forsection 'true' if this is a section edit form, 'false' if this is course edit form.
     * @return array array of references to the added form elements.
     */
    public function create_edit_form_elements(&$mform, $forsection = false) {
        $elements = parent::create_edit_form_elements($mform, $forsection);

        // Increase the number of sections combo box values if the user has increased the number of sections
        // using the icon on the course page beyond course 'maxsections' or course 'maxsections' has been
        // reduced below the number of sections already set for the course on the site administration course
        // defaults page.  This is so that the number of sections is not reduced leaving unintended orphaned
        // activities / resources.
        if (!$forsection) {
            $maxsections = get_config('moodlecourse', 'maxsections');
            $numsections = $mform->getElementValue('numsections');
            $numsections = $numsections[0];
            if ($numsections > $maxsections) {
                $element = $mform->getElement('numsections');
                for ($i = $maxsections+1; $i <= $numsections; $i++) {
                    $element->addOption("$i", $i);
                }
            }
        }
        return $elements;
    }

    /**
     * Updates format options for a course
     *
     * In case if course format was changed to 'periods', we try to copy options
     * 'coursedisplay', 'numsections' and 'hiddensections' from the previous format.
     * If previous course format did not have 'numsections' option, we populate it with the
     * current number of sections
     *
     * @param stdClass|array $data return value from {@link moodleform::get_data()} or array with data
     * @param stdClass $oldcourse if this function is called from {@link update_course()}
     *     this object contains information about the course before update
     * @return bool whether there were any changes to the options values
     */
    public function update_course_format_options($data, $oldcourse = null) {
        global $DB;
        if ($oldcourse !== null) {
            $data = (array)$data;
            $oldcourse = (array)$oldcourse;
            $options = $this->course_format_options();
            foreach ($options as $key => $unused) {
                if (!array_key_exists($key, $data)) {
                    if (array_key_exists($key, $oldcourse)) {
                        $data[$key] = $oldcourse[$key];
                    } else if ($key === 'numsections') {
                        // If previous format does not have the field 'numsections'
                        // and $data['numsections'] is not set,
                        // we fill it with the maximum section number from the DB
                        $maxsection = $DB->get_field_sql('SELECT max(section) from {course_sections}
                            WHERE course = ?', array($this->courseid));
                        if ($maxsection) {
                            // If there are no sections, or just default 0-section, 'numsections' will be set to default
                            $data['numsections'] = $maxsection;
                        }
                    }
                }
            }
        }
        return $this->update_format_options($data);
    }

    /**
     * Return the start and end date of the passed section
     *
     * @param int|stdClass|section_info $section section to get the dates for
     * @return stdClass property start for startdate, property end for enddate
     */
    public function get_section_dates($section) {
        $course = $this->get_course();
        if (is_object($section)) {
            $sectionnum = $section->section;
        } else {
            $sectionnum = $section;
        }

        // Hack alert. We add 2 hours to avoid possible DST problems. (e.g. we go into daylight
        // savings and the date changes.
        $startdate = $course->startdate + 7200;

        $dates = new stdClass();
        if (is_int($course->periodduration)) {
            $oneperiodseconds = $course->periodduration;
            $dates->start = $startdate + ($oneperiodseconds * ($sectionnum - 1));
            $dates->end = $dates->start + $oneperiodseconds;
        } else {
            $dates->start = $startdate;
            for ($i = 0; $i < $sectionnum - 1; $i++) {
                $dates->start = strtotime($course->periodduration, $dates->start);
            }
            $dates->end = strtotime($course->periodduration, $dates->start);
        }
        return $dates;
    }

    /**
     * Returns true if the specified week is current
     *
     * @param int|stdClass|section_info $section
     * @return bool
     */
    public function is_section_current($section) {
        if (is_object($section)) {
            $sectionnum = $section->section;
        } else {
            $sectionnum = $section;
        }
        if ($sectionnum < 1) {
            return false;
        }
        $timenow = time();
        $dates = $this->get_section_dates($section);
        return (($timenow >= $dates->start) && ($timenow < $dates->end));
    }

    public function get_section_display_mode($section) {
        $course = $this->get_course();
        $displaytype = $course->coursedisplay;

        if ($course->showfutureperiods == FORMAT_PERIODS_AS_ABOVE &&
                $course->showpastperiods == FORMAT_PERIODS_AS_ABOVE &&
                $course->showpastcompleted == FORMAT_PERIODS_AS_ABOVE) {
            // Shortcut, nothing else to do.
            return $displaytype;
        }

        $dates = $this->get_section_dates($section);
        $timenow = time();
        if ($dates->start > $timenow + $course->futuresneakpeek) {
            // This is a future section.
            if ($course->showfutureperiods != FORMAT_PERIODS_AS_ABOVE) {
                $displaytype = $course->showfutureperiods;
            }
        } else if ($dates->end < $timenow) {
            // This is a past section.
            if ($course->showpastperiods != FORMAT_PERIODS_AS_ABOVE) {
                $displaytype = $course->showpastperiods;
            }
            if ($course->showpastcompleted != FORMAT_PERIODS_AS_ABOVE) {
                if ($this->is_section_completed($section)) {
                    $displaytype = $course->showpastcompleted;
                }
            }
        }
        return $displaytype;
    }

    /**
     * Allows to specify for modinfo that section is not available even when it is visible and conditionally available.
     *
     * Note: affected user can be retrieved as: $section->modinfo->userid
     *
     * Course format plugins can override the method to change the properties $available and $availableinfo that were
     * calculated by conditional availability.
     * To make section unavailable set:
     *     $available = false;
     * To make unavailable section completely hidden set:
     *     $availableinfo = '';
     * To make unavailable section visible with availability message set:
     *     $availableinfo = get_string('sectionhidden', 'format_xxx');
     *
     * @param section_info $section
     * @param bool $available the 'available' propery of the section_info as it was evaluated by conditional availability.
     *     Can be changed by the method but 'false' can not be overridden by 'true'.
     * @param string $availableinfo the 'availableinfo' propery of the section_info as it was evaluated by conditional availability.
     *     Can be changed by the method
     */
    public function section_get_available_hook(section_info $section, &$available, &$availableinfo) {
        if (!$available || !$section->section) {
            return;
        }
        $displaytype = $this->get_section_display_mode($section);
        if ($displaytype == FORMAT_PERIODS_HIDDEN) {
            $available = false;
            $availableinfo = '';
        } else if ($displaytype == FORMAT_PERIODS_NOTAVAILABLE) {
            $available = false;
            $availableinfo = get_string('notavailable', 'format_periods');
        }
    }

    protected $completioninfo = null;

    /**
     * Evaluates if the section is completed.
     *
     * If the section was not completed at the start of the session but became
     * completed, this function will still return false.
     *
     * @param section_info $section
     * @return bool
     */
    public function is_section_completed(section_info $section) {
        global $SESSION;
        if (!empty($SESSION->format_periods[$this->courseid][$section->section])) {
            // This section was not completed at the beginning of the session,
            // consider it to be still not completed.
            return false;
        }
        if ($this->completioninfo === null) {
            $this->completioninfo = new completion_info($this->get_course());
        }
        $modinfo = $section->modinfo;
        if (!empty($modinfo->sections[$section->section])) {
            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->cms[$cmid];

                $completion = $this->completioninfo->is_enabled($cm);
                if ($completion != COMPLETION_TRACKING_NONE) {
                    $completiondata = $this->completioninfo->get_data($cm, true);
                    if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                            $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                        // Section completed.
                        continue;
                    }
                }
                // This section is not completed. Remember this in the session so we
                // don't hide this section even if user completes everything.
                if (empty($SESSION->format_periods)) {
                    $SESSION->format_periods = array();
                }
                if (empty($SESSION->format_periods[$this->courseid])) {
                    $SESSION->format_periods[$this->courseid] = array();
                }
                $SESSION->format_periods[$this->courseid][$section->section] = 1;
                return false;
            }
        }
        return true;
    }
}
