moodle-format_periods
=====================

Version 3.3.1
-------------

- Version for Moodle 3.3 and 3.4
- Small CSS fixes
- Respect $CFG->linkcoursesections

Version 3.3.0
-------------

- Compatibility with Moodle 3.3
- Stealth activities
- Removed 'Number of sections', sections can be added when needed
- Automatic end date calculation

Version 3.0.4
-------------

- Compatibility with Moodle 3.2, Boost and PHP7.1 (still works on previous versions)

Version 3.0.3
-------------

- Compatibility with section name editing in Moodle 3.1 (still works on previous
  versions)

Version 3.0.2
-------------

- Allow to delete periods

Version 3.0.1
-------------

- Fixed localisation of period duration, see issues #1 and #2 in github

Version 3.0.0
-------------

- Compatibility with Moodle 3.0

Version 2.8.2
-------------

- On the course page display for teachers the period dates if the section name
was overridden and also duration if it is not standard.
- Allow to choose format for the dates
- Fixed as many codechecker/moodlecheck complains as possible

Version 2.8.1 (Initial version)
-------------------------------

This course format allows to set duration for each section (period) in days,
weeks, months or years. Each individual section (period) may override this
duration.

The course settings allow automatically collapse or hide past or future periods.
