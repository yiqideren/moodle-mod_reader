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
 * Create a table to display attempts at a Reader activity
 *
 * @package   mod-reader
 * @copyright 2013 Gordon Bateson <gordon.bateson@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// get parent class
require_once($CFG->dirroot.'/mod/reader/admin/reports/tablelib.php');

/**
 * reader_admin_reports_userdetailed_table
 *
 * @copyright 2013 Gordon Bateson
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class reader_admin_reports_userdetailed_table extends reader_admin_reports_table {

    /** @var columns used in this table */
    protected $tablecolumns = array(
        'studentview', 'username', 'fullname', 'currentlevel', 'difficulty', 'name',
        'selected', 'timefinish', 'duration', 'grade', 'passed',
        'words', 'totalwords', 'points', 'totalpoints'
    );

    /** @var suppressed columns in this table */
    protected $suppresscolumns = array('studentview', 'username', 'fullname', 'currentlevel');

    /** @var columns in this table that are not sortable */
    protected $nosortcolumns = array('totalwords');

    /** @var text columns in this table */
    protected $textcolumns = array('username', 'fullname', 'name');

    /** @var number columns in this table */
    protected $numbercolumns = array('currentlevel', 'difficulty', 'words', 'totalwords', 'points', 'totalpoints');

    /** @var columns that are not to be center aligned */
    protected $leftaligncolumns = array('username', 'fullname', 'name');

    /** @var default sort columns */
    //protected $defaultsortcolumns = array('username' => SORT_ASC, 'lastname' => SORT_ASC, 'firstname' => SORT_ASC, 'timefinish' => SORT_DESC, 'name' => SORT_ASC);
    protected $defaultsortcolumns = array('username' => SORT_ASC, 'timefinish' => SORT_DESC);

    /** @var filter fields ($fieldname => $advanced) */
    protected $filterfields = array(
        'group'      => 0, 'username'     => 1, 'realname'     => 0,
        'lastname'   => 1, 'firstname'    => 1, 'currentlevel' => 1,
        'difficulty' => 1, 'name'         => 1,
        'timefinish' => 1, 'duration'     => 1,
        'grade'      => 1, 'passed'       => 1,
        'words'      => 1, //'totalwords' => 1,
        'points'     => 1, //'totalpoints' => 1,
    );

    /** @var option fields */
    protected $optionfields = array('rowsperpage' => self::DEFAULT_ROWSPERPAGE,
                                    'showhidden'  => self::DEFAULT_SHOWHIDDEN,
                                    'showdeleted' => self::DEFAULT_SHOWDELETED,
                                    'sortfields'  => array());

    /** @var actions */
    protected $actions = array('deleteattempts', 'restoreattempts', 'passfailattempts');

    ////////////////////////////////////////////////////////////////////////////////
    // functions to extract data from $DB                                         //
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Constructor
     *
     * @param int $uniqueid
     */
    public function __construct($uniqueid, $output) {
        if ($output->reader->wordsorpoints==0) {
            $values = array('points', 'totalpoints');
        } else {
            $values = array('words', 'totalwords');
        }
        foreach ($values as $value) {
            if ($i = array_search($value, $this->tablecolumns)) {
                array_splice($this->tablecolumns, $i, 1);
            }
            if ($i = array_search($value, $this->numbercolumns)) {
                array_splice($this->numbercolumns, $i, 1);
            }
            if (array_key_exists($value, $this->filterfields)) {
                unset($this->filterfields[$value]);
            }
        }
        parent::__construct($uniqueid, $output);
    }

    /**
     * select_sql
     *
     * @uses $DB
     * @param xxx $userid (optional, default=0)
     * @param xxx $attemptid (optional, default=0)
     * @return xxx
     */
    function select_sql($userid=0, $attemptid=0) {

        // get users who can access this Reader activity
        list($usersql, $userparams) = $this->select_sql_users();

        if ($this->output->reader->wordsorpoints==0) {
            $field1 = 'rb.words';
            $field2 = 'words';
            $field3 = 'totalwords';
        } else {
            $field1 = 'rb.length';
            $field2 = 'points';
            $field3 = 'totalpoints';
        }

        $score    = 'CASE WHEN (ra.passed = :passed) THEN '.$field1.' ELSE 0 END';
        $grade    = 'CASE WHEN (ra.percentgrade IS NULL) THEN 0 ELSE ra.percentgrade END';
        $duration = 'CASE WHEN (ra.timefinish IS NULL OR ra.timefinish = 0) THEN 0 ELSE (ra.timefinish - ra.timestart) END';

        $select = "ra.id, ra.timefinish, ($duration) as duration, ($grade) as grade, ra.passed, ($score) AS $field2, 0 AS $field3, ".
                  $this->get_userfields('u', array('username'), 'userid').', '.
                  'rl.currentlevel, rb.difficulty, rb.name';
        $from   = '{reader_attempts} ra '.
                  'LEFT JOIN {user} u ON ra.userid = u.id '.
                  'LEFT JOIN {reader_levels} rl ON ra.reader = rl.readerid AND u.id = rl.userid '.
                  'LEFT JOIN {reader_books} rb ON ra.bookid = rb.id';
        $where  = "ra.reader = :reader AND ra.timefinish > :time AND u.id $usersql";

        $params = array('reader'   => $this->output->reader->id,
                        'time'     => $this->output->reader->ignoredate,
                        'passed'   => 'true') + $userparams;

        if ($this->output->reader->bookinstances) {
            $from  .= ' LEFT JOIN {reader_book_instances} rbi ON rb.id = rbi.bookid';
            $where .= ' AND rbi.id IS NOT NULL AND rbi.readerid = :rbireader';
            $params['rbireader'] = $this->output->reader->id;
        }

        return $this->add_filter_params($select, $from, $where, '', '', '', $params);
    }

    /**
     * get_table_name_and_alias
     *
     * @param string $fieldname
     * @return array($tablename, $tablealias, $jointype, $jointable, $joinconditions)
     * @todo Finish documenting this function
     */
    public function get_table_name_and_alias($fieldname) {
        switch ($fieldname) {

            case 'currentlevel':
                return array('reader_levels', 'rl');

            case 'name':
            case 'words':
            case 'difficulty':
                return array('reader_levels', 'rb');

            case 'totalwords':
                return array('', '');

            case 'timefinish':
            case 'passed':
                return array('reader_attempts', 'ra');

            default:
                return parent::get_table_name_and_alias($fieldname);
        }
    }

    ////////////////////////////////////////////////////////////////////////////////
    // functions to format header cells                                           //
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * header_words
     *
     * @return xxx
     */
    public function header_words() {
        return get_string('words', 'mod_reader');
    }

    /**
     * header_points
     *
     * @return xxx
     */
    public function header_points() {
        return get_string('points', 'mod_reader');
    }

    /**
     * header_totalwords
     *
     * @return xxx
     */
    public function header_totalwords() {
        return $this->header_total();
    }

    /**
     * header_totalpoints
     *
     * @return xxx
     */
    public function header_totalpoints() {
        return $this->header_total();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // functions to format data cells                                             //
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * col_totalwords
     *
     * @param xxx $row
     * @return xxx
     */
    public function col_totalwords($row)  {
        return $this->col_total($row, 'words');
    }

    /**
     * col_totalpoints
     *
     * @param xxx $row
     * @return xxx
     */
    public function col_totalpoints($row)  {
        return $this->col_total($row, 'points');
    }

    /**
     * col_totalwords
     *
     * @param xxx $row
     * @param xxx $field
     * @return xxx
     */
    public function col_total($row, $field)  {
        static $userid = 0;
        static $total = 0;

        if (empty($row->userid)) {
            return $this->empty_cell(); // shouldn't happen !!
        }

        if ($userid && $userid==$row->userid) {
            // same user
        } else {
            $userid = $row->userid;
            $total = 0;
        }

        if (isset($row->passed) && $row->passed=='true' && isset($row->$field)) {
            $total += $row->$field;
        }

        return number_format($total);
    }
}
