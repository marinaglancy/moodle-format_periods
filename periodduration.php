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
 * Duration form element
 *
 * Contains class to create length of time for element.
 *
 * @package   format_periods
 * @copyright 2015 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;
require_once($CFG->libdir . '/form/group.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/form/text.php');

MoodleQuickForm::registerElementType('periodduration', "$CFG->dirroot/course/format/periods/periodduration.php",
    'format_periods_periodduration');

/**
 * Period duration element
 *
 * HTML class for a length of days/weeks/months.
 * The values returned to PHP as string to use in strtotime(), for example
 * '1 day', '2 week', '3 month', etc..
 *
 * @package   format_periods
 * @copyright 2015 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_periods_periodduration extends MoodleQuickForm_group {
    /**
     * Control the fieldnames for form elements
     * optional => if true, show a checkbox beside the element to turn it on (or off)
     * @var array
     */
    protected $_options = array('optional' => false, 'default' => '1 week', 'defaultunit' => 'week');

    /** @var array associative array of time units (days, hours, minutes, seconds) */
    private $_units = null;

    /**
     * constructor
     *
     * @param string $elementname Element's name
     * @param mixed $elementlabel Label(s) for an element
     * @param array $options Options to control the element's display. Recognised values are
     *              'optional' => true/false - whether to display an 'enabled' checkbox next to the element.
     *              'defaultunit' => day, week, month, year - the default unit to display when the time is blank.
     *              'defaulttime' => the default number of units to display when the time is blank
     *              If not specified, minutes is used.
     * @param mixed $attributes Either a typical HTML attribute string or an associative array
     */
    public function __construct($elementname = null, $elementlabel = null, $options = array(), $attributes = null) {
        parent::__construct($elementname, $elementlabel);
        $this->setAttributes($attributes);
        $this->_persistantFreeze = true;
        $this->_appendName = true;
        $this->_type = 'duration';

        // Set the options, do not bother setting bogus ones.
        if (!is_array($options)) {
            $options = array();
        }
        $this->_options['optional'] = !empty($options['optional']);
        if (isset($options['defaultunit'])) {
            if (!array_key_exists($options['defaultunit'], $this->get_units())) {
                throw new coding_exception($options['defaultunit'] .
                        ' is not a recognised unit in format_periods_periodduration.');
            }
            $this->_options['defaultunit'] = $options['defaultunit'];
        }
        if (array_key_exists('default', $options)) {
            $this->_options['default'] = $options['default'];
        }
    }

    /**
     * Returns time associative array of unit length.
     *
     * @return array unit length in seconds => string unit name.
     */
    public function get_units() {
        if (is_null($this->_units)) {
            $this->_units = array(
                'day' => get_string('numdays', 'moodle', ''),
                'week' => get_string('numweeks', 'moodle', ''),
                'month' => get_string('nummonths', 'moodle', ''),
                'year' => get_string('numyears', 'moodle', ''),
            );
        }
        return $this->_units;
    }

    /**
     * Converts value to the best possible time unit. for example
     * '2 week' -> array(2, 'week')
     *
     * @param string $value an amout of time in seconds or text value (i.e. '2 week')
     * @return array associative array ($number => $unit)
     */
    public function value_to_unit($value) {
        if (preg_match('/^(\d+) (\w+)$/', $value, $matches) &&
                array_key_exists($matches[2], $this->get_units())) {
            return array((int)$matches[1], $matches[2]);
        }
        if (is_int($value)) {
            if (is_int($value / WEEKSECS)) {
                return array($value / WEEKSECS, 'week');
            } else {
                return array((int)($value / DAYSECS), 'day');
            }
        }
        if (preg_match('/^(\d+) (\w+)$/', $this->_options['default'], $matches) &&
                array_key_exists($matches[2], $this->get_units())) {
            return array((int)$matches[1], $matches[2]);
        }
        return array(0, $this->_options['defaultunit']);
    }

    /**
     * Override of standard quickforms method to create this element.
     */
    public function _createElements() {
        $attributes = $this->getAttributes();
        if (is_null($attributes)) {
            $attributes = array();
        }
        if (!isset($attributes['size'])) {
            $attributes['size'] = 3;
        }
        $this->_elements = array();
        // E_STRICT creating elements without forms is nasty because it internally uses $this.
        $this->_elements[] = $this->createFormElement('text', 'number', get_string('time', 'form'), $attributes, true);
        unset($attributes['size']);
        $this->_elements[] = $this->createFormElement('select', 'timeunit',
            get_string('timeunit', 'form'), $this->get_units(), $attributes, true);
        // If optional we add a checkbox which the user can use to turn if on.
        if ($this->_options['optional']) {
            $this->_elements[] = $this->createFormElement('checkbox', 'enabled', null,
                get_string('enable'), $this->getAttributes(), true);
        }
        foreach ($this->_elements as $element) {
            if (method_exists($element, 'setHiddenLabel')) {
                $element->setHiddenLabel(true);
            }
        }
    }

    /**
     * Called by HTML_QuickForm whenever form event is made on this element
     *
     * @param string $event Name of event
     * @param mixed $arg event arguments
     * @param object $caller calling object
     * @return bool
     */
    public function onQuickFormEvent($event, $arg, &$caller) {
        $this->setMoodleForm($caller);
        switch ($event) {
            case 'updateValue':
                // Constant values override both default and submitted ones,
                // default values are overriden by submitted.
                $value = $this->_findValue($caller->_constantValues);
                if (null === $value) {
                    // If no boxes were checked, then there is no value in the array
                    // yet we don't want to display default value in this case.
                    if ($caller->isSubmitted()) {
                        $value = $this->_findValue($caller->_submitValues);
                    } else {
                        $value = $this->_findValue($caller->_defaultValues);
                    }
                }
                if (!is_array($value)) {
                    list($number, $unit) = $this->value_to_unit($value);
                    $value = array('number' => $number, 'timeunit' => $unit);
                    // If optional, default to off, unless a date was provided.
                    if ($this->_options['optional']) {
                        $value['enabled'] = $number != 0;
                    }
                } else {
                    $value['enabled'] = isset($value['enabled']);
                }
                if (null !== $value) {
                    $this->setValue($value);
                }
                break;

            case 'createElement':
                if (!empty($arg[2]['optional'])) {
                    $caller->disabledIf($arg[0], $arg[0] . '[enabled]');
                }
                $caller->setType($arg[0] . '[number]', PARAM_INT);
                return parent::onQuickFormEvent($event, $arg, $caller);
                break;

            default:
                return parent::onQuickFormEvent($event, $arg, $caller);
        }
    }

    /**
     * Returns HTML for advchecbox form element.
     *
     * @return string
     */
    public function toHtml() {
        include_once('HTML/QuickForm/Renderer/Default.php');
        $renderer = new HTML_QuickForm_Renderer_Default();
        $renderer->setElementTemplate('{element}');
        parent::accept($renderer);
        return $renderer->toHtml();
    }

    /**
     * Accepts a renderer
     *
     * @param HTML_QuickForm_Renderer $renderer An HTML_QuickForm_Renderer object
     * @param bool $required Whether a group is required
     * @param string $error An error message associated with a group
     */
    public function accept(&$renderer, $required = false, $error = null) {
        $renderer->renderElement($this, $required, $error);
    }

    /**
     * Output a timestamp. Give it the name of the group.
     * Override of standard quickforms method.
     *
     * @param  array $submitvalues
     * @param  bool  $notused Not used.
     * @return array field name => value. The value is the time interval in seconds.
     */
    public function exportValue(&$submitvalues, $notused = false) {
        // Get the values from all the child elements.
        $valuearray = array();
        foreach ($this->_elements as $element) {
            $thisexport = $element->exportValue($submitvalues[$this->getName()], true);
            if (!is_null($thisexport)) {
                $valuearray += $thisexport;
            }
        }

        // Convert the value to an integer number of seconds.
        if (empty($valuearray)) {
            return null;
        }
        if ($this->_options['optional'] && empty($valuearray['enabled'])) {
            return array($this->getName() => 0);
        }
        return array($this->getName() => $valuearray['number'] . ' ' . $valuearray['timeunit']);
    }
}
