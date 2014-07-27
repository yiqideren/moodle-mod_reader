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
 * mod/reader/quiz/attemptlib.php
 * Back-end code for handling data about readerzes and the current user's attempt.
 *
 * There are classes for loading all the information about a reader and attempts,
 * and for displaying the navigation panel.
 *
 * @package    mod
 * @subpackage reader
 * @copyright  2008 onwards Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Prevent direct access to this script */
defined('MOODLE_INTERNAL') || die;

/**
 * moodle_reader_exception
 * Class for reader exceptions. Just saves a couple of arguments on the
 * constructor for a moodle_exception.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 * @package    mod
 * @subpackage reader
 */
class moodle_reader_exception extends moodle_exception {

    /**
     * __construct
     *
     * @param xxx $readerquiz
     * @param xxx $errorcode
     * @param xxx $a (optional, default=null)
     * @param xxx $link (optional, default='')
     * @param xxx $debuginfo (optional, default=null)
     * @todo Finish documenting this function
     */
    public function __construct($readerquiz, $errorcode, $a = null, $link = '', $debuginfo = null) {
        if (! $link) {
            $link = $readerquiz->view_url();
        }
        parent::__construct($errorcode, 'reader', $link, $a, $debuginfo);
    }
}

/**
 * A class encapsulating a reader and the questions it contains, and making the
 * information available to scripts like view.php.
 *
 * Initially, it only loads a minimal amout of information about each question - loading
 * extra information only when necessary or when asked. The class tracks which questions
 * are loaded.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class reader_quiz {
    // Fields initialised in the constructor.
    public $course;
    public $cm;
    public $reader;
    public $context;
    public $book;
    public $quiz;
    public $questionids;

    // Fields set later if that data is needed.
    public $questions = null;
    public $accessmanager = null;
    public $ispreviewuser = null;

    // Constructor =========================================================================
    /**
     * Constructor, assuming we already have the necessary data loaded.
     *
     * @param object $reader the row from the reader table.
     * @param object $cm the course_module object for this reader.
     * @param object $course the course object for this reader.
     * @param object $book the book object for this reader.
     * @param object $quiz the quiz object for this reader.
     * @param bool $getcontext intended for testing - stops the constructor getting the context.
     */
    public function __construct($reader, $cm, $course, $book, $quiz, $getcontext = true) {
        global $DB;

        $this->reader = $reader;
        $this->cm = $cm;

        $this->course = $course;
        if ($getcontext && !empty($cm->id)) {
            $this->context = reader_get_context(CONTEXT_MODULE, $cm->id);
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('quiz_slots')) { // Moodle >= 2.7
            if ($quiz->questions = $DB->get_records_menu('quiz_slots', array('quizid' => $quiz->id), 'page,slot', 'id,questionid')) {
                $quiz->questions = array_values($quiz->questions);
                $quiz->questions = array_filter($quiz->questions);
                $quiz->questions = implode(',', $quiz->questions);
            } else {
                $quiz->questions = '';
            }
        }

        $this->book = $book;
        $this->quiz = $quiz;

        $this->questionids = explode(',', $this->quiz->questions);
        $this->questionids = array_map('intval', $this->questionids);
        $this->questionids = array_filter($this->questionids); // remove blanks
    }

    /**
     * Static function to create a new reader object for a specific user.
     *
     * @param int $readerid the the reader id.
     * @param int $userid the the userid.
     * @return reader the new reader object
     */
    public static function create($readerid, $userid, $bookid) {
        global $DB;

        $reader = $DB->get_record('reader', array('id' => $readerid), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $reader->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('reader', $reader->id, $course->id, false, MUST_EXIST);

        $book = $DB->get_record('reader_books', array('id' => $bookid), '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', array('id' => $book->quizid),   '*', MUST_EXIST);

        $quiz->timelimit         = $reader->timelimit;
        $quiz->attempts          = 1;
        $quiz->questionsperpage  = 1;
        $quiz->shuffleanswers    = 1;
        $quiz->quizpassword      = $reader->password;
        $quiz->subnet            = $reader->subnet;

        // Update reader with override information
        //$reader = reader_update_effective_access($reader, $userid);

        return new reader_quiz($reader, $cm, $course, $book, $quiz);
    }

    // Functions for loading more data =====================================================

    /**
     * Load just basic information about all the questions in this reader.
     */
    public function preload_questions() {
        if (empty($this->questionids)) {
            throw new moodle_reader_exception($this, 'noquestions', $this->edit_url());
        }
        $select = 'qqi.grade AS maxmark, qqi.id AS instance';
        $from   = '{reader_question_instances} qqi ON qqi.quiz = :quizid AND q.id = qqi.question';
        $params = array('quizid' => $this->quiz->id);
        $this->questions = question_preload_questions($this->questionids, $select, $from, $params);
    }

    /**
     * Fully load some or all of the questions for this reader. You must call
     * {@link preload_questions()} first.
     *
     * @param array $questionids question ids of the questions to load. null for all.
     */
    public function load_questions($questionids = null) {
        if (is_null($questionids)) {
            $questionids = $this->questionids;
        }
        $questionstoprocess = array();
        foreach ($questionids as $id) {
            if (array_key_exists($id, $this->questions)) {
                $questionstoprocess[$id] = $this->questions[$id];
            }
        }
        get_question_options($questionstoprocess);
    }

    /** @return int the course id. */
    public function get_courseid() {
        return $this->course->id;
    }

    /** @return object the row of the course table. */
    public function get_course() {
        return $this->course;
    }

    /** @return int the reader id. */
    public function get_readerid() {
        return $this->reader->id;
    }

    /** @return object the row of the reader table. */
    public function get_reader() {
        return $this->reader;
    }

    /** @return object the quiz record for this reader. */
    public function get_quiz() {
        return $this->quiz;
    }

    /** @return string the name of this reader. */
    public function get_reader_name() {
        return $this->reader->name;
    }

    /** @return int the number of attempts allowed at this reader (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->reader->attempts;
    }

    /** @return int the course_module id. */
    public function get_cmid() {
        return $this->cm->id;
    }

    /** @return object the course_module object. */
    public function get_cm() {
        return $this->cm;
    }

    /** @return object the module context for this reader. */
    public function get_context() {
        return $this->context;
    }

    /**
     * @return bool wether the current user is someone who previews the reader,
     * rather than attempting it.
     */
    public function is_preview_user() {
        if (is_null($this->ispreviewuser)) {
            $this->ispreviewuser = has_capability('mod/reader:viewbooks', $this->context);
        }
        return $this->ispreviewuser;
    }

    /**
     * @return whether any questions have been added to this reader.
     */
    public function has_questions() {
        return !empty($this->questionids);
    }

    /**
     * @param int $id the question id.
     * @return object the question object with that id.
     */
    public function get_question($id) {
        if (empty($this->questions[$id])) {
            return null;
        } else {
            return $this->questions[$id];
        }
    }

    /**
     * @param array $questionids question ids of the questions to load. null for all.
     */
    public function get_questions($questionids = null) {
        if (is_null($questionids)) {
            $questionids = $this->questionids;
        }
        $questions = array();
        foreach ($questionids as $id) {
            if (! array_key_exists($id, $this->questions)) {
                throw new moodle_exception('cannotstartmissingquestion', 'reader', $this->view_url());
            }
            $questions[$id] = $this->questions[$id];
            $this->ensure_question_loaded($id);
        }
        return $questions;
    }

    /**
     * @param int $timenow the current time as a unix timestamp.
     * @return reader_access_manager and instance of the reader_access_manager class
     *      for this reader at this time.
     */
    public function get_access_manager($timenow) {
        if (is_null($this->accessmanager)) {
            $this->accessmanager = new reader_access_manager($this, $timenow, true);
        }
        return $this->accessmanager;
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the reader context.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return has_capability($capability, $this->context, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability funciton that automatically passes in the reader context.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        return require_capability($capability, $this->context, $userid, $doanything);
    }

    // URLs related to this attempt ========================================================
    /**
     * @return string the URL of this reader's view page.
     */
    public function view_url() {
        global $CFG;
        return $CFG->wwwroot.'/mod/reader/view.php?id=' . $this->cm->id;
    }

    /**
     * @return string the URL of this reader's edit page.
     */
    public function edit_url() {
        global $CFG;
        return $CFG->wwwroot.'/mod/reader/view.php?id=' . $this->cm->id;
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @param int $page optional page number to go to in the attempt.
     * @return string the URL of that attempt.
     */
    public function attempt_url($attemptid, $page=0) {
        global $CFG;
        $url = $CFG->wwwroot.'/mod/reader/quiz/attempt.php?attempt=' . $attemptid;
        if ($page) {
            $url .= '&page=' . $page;
        }
        return $url;
    }

    /**
     * @return string the URL of this reader's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($page=0) {
        $params = array('cmid' => $this->cm->id, 'sesskey' => sesskey());
        if ($page) {
            $params['page'] = $page;
        }
        return new moodle_url('/mod/reader/quiz/startattempt.php', $params);
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @return string the URL of the review of that attempt.
     */
    public function review_url($attemptid) {
        return new moodle_url('/mod/reader/review.php', array('attempt' => $attemptid));
    }

    // Bits of content =====================================================================

    /**
     * @param string $title the name of this particular reader page.
     * @return array the data that needs to be sent to print_header_simple as the $navigation
     * parameter.
     */
    public function navigation($title) {
        global $PAGE;
        $PAGE->navbar->add($title);
        return '';
    }

    /**
     * Check that the definition of a particular question is loaded, and if not throw an exception.
     * @param $id a questionid.
     */
    public function ensure_question_loaded($id) {
        if (isset($this->questions[$id]->_partiallyloaded)) {
            throw new moodle_reader_exception($this, 'questionnotloaded', $id);
        }
    }
}

/**
 * This class extends the reader class to hold data about the state of a particular attempt,
 * in addition to the data about the reader.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class reader_attempt {
    // Fields initialised in the constructor.
    public $readerquiz;
    public $attempt;
    public $quba;

    // Fields set later if that data is needed.
    public $pagelayout; // array page no => array of numbers on the page in order.
    public $reviewoptions = null;

    // Constructor =========================================================================
    /**
     * Constructor assuming we already have the necessary data loaded.
     *
     * @param object $attempt the row of the reader_attempts table.
     * @param object $reader the reader object for this attempt and user.
     * @param object $cm the course_module object for this reader.
     * @param object $course the row from the course table for the course we belong to.
     */
    public function __construct($attempt, $reader, $cm, $course) {
        global $DB;

        $this->attempt = $attempt;

        $book = $DB->get_record('reader_books', array('quizid' => $attempt->quizid), '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz',         array('id'     => $attempt->quizid), '*', MUST_EXIST);

        $this->readerquiz = reader_quiz::create($reader->id, $attempt->userid, $book->id);
        $this->quba = question_engine::load_questions_usage_by_activity($this->attempt->uniqueid);

        $this->determine_layout();
        $this->number_questions();
    }

    /**
     * Used by {create()} and {create_from_usage_id()}.
     * @param array $attempt_or_uniqueid passed to $DB->get_record('reader_attempts', $attemptid).
     */
    public static function create_helper($attempt_or_uniqueid) {
        global $DB;

        $attempt = $DB->get_record('reader_attempts', $attempt_or_uniqueid,            '*', MUST_EXIST);
        $reader  = $DB->get_record('reader',          array('id' => $attempt->reader), '*', MUST_EXIST);
        $course  = $DB->get_record('course',          array('id' => $reader->course),  '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('reader', $reader->id, $course->id, false, MUST_EXIST);

        // Update reader with override information
        //$reader = reader_update_effective_access($reader, $attempt->userid);

        return new reader_attempt($attempt, $reader, $cm, $course);
    }

    /**
     * Static function to create a new reader_attempt object given an attemptid.
     *
     * @param int $attemptid the attempt id.
     * @return reader_attempt the new reader_attempt object
     */
    public static function create($attemptid) {
        return self::create_helper(array('id' => $attemptid));
    }

    /**
     * Static function to create a new reader_attempt object given a usage id.
     *
     * @param int $usageid the attempt usage id.
     * @return reader_attempt the new reader_attempt object
     */
    public static function create_from_usage_id($usageid) {
        return self::create_helper(array('uniqueid' => $usageid));
    }

    /**
     * determine_layout
     *
     * @todo Finish documenting this function
     */
    private function determine_layout() {
        $this->pagelayout = array();

        // Break up the layout string into pages.
        $pagelayouts = explode(',0', reader_clean_layout($this->attempt->layout, true));

        // Strip off any empty last page (normally there is one).
        if (end($pagelayouts) == '') {
            array_pop($pagelayouts);
        }

        // File the ids into the arrays.
        $this->pagelayout = array();
        foreach ($pagelayouts as $page => $pagelayout) {
            $pagelayout = trim($pagelayout, ',');
            if ($pagelayout == '') {
                continue;
            }
            $this->pagelayout[$page] = explode(',', $pagelayout);
        }
    }

    /**
     * number_questions
     *
     * @todo Finish documenting this function
     */
    private function number_questions() {
        $number = 1;
        foreach ($this->pagelayout as $page => $slots) {
            foreach ($slots as $slot) {
                $question = $this->quba->get_question($slot);
                if ($question->length > 0) {
                    $question->_number = $number;
                    $number += $question->length;
                } else {
                    $question->_number = get_string('infoshort', 'mod_reader');
                }
                $question->_page = $page;
            }
        }
    }

    /**
     * set_rating
     *
     * @uses $DB
     * @uses $USER
     * @param xxx $likebook
     * @todo Finish documenting this function
     */
    public function set_rating($likebook) {
        global $DB, $USER;

        if (isset($likebook)){
            $this->attempt->bookrating = $likebook;
            $this->attempt->ip         = $this->ip();
        }
    }

    /**
     * ip
     *
     * @return xxx
     * @todo Finish documenting this function
     */
    public function ip() {
      if (isset($_SERVER)) {
        if(isset($_SERVER['HTTP_CLIENT_IP'])){
          $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif(isset($_SERVER['HTTP_FORWARDED_FOR'])){
          $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif(isset($_SERVER['HTTP_X_FORWARDED_FOR'])){
          $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else{
          $ip = $_SERVER['REMOTE_ADDR'];
        }
      } else {
        if (getenv( 'HTTP_CLIENT_IP')) {
          $ip = getenv( 'HTTP_CLIENT_IP' );
        } elseif (getenv('HTTP_FORWARDED_FOR')) {
          $ip = getenv('HTTP_FORWARDED_FOR');
        } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
          $ip = getenv('HTTP_X_FORWARDED_FOR');
        } else {
          $ip = getenv('REMOTE_ADDR');
        }
      }
      return $ip;
    }

    /**
     * get_reader
     *
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_reader() {
        return $this->readerquiz->get_reader();
    }

    /**
     * get_quiz
     *
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_quiz() {
        return $this->readerquiz->get_quiz();
    }

    /**
     * get_readerquiz
     *
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_readerquiz() {
        return $this->readerquiz;
    }

    /** @return int the course id. */
    public function get_courseid() {
        return $this->readerquiz->get_courseid();
    }

    /** @return int the course id. */
    public function get_course() {
        return $this->readerquiz->get_course();
    }

    /** @return int the reader id. */
    public function get_readerid() {
        return $this->readerquiz->get_readerid();
    }

    /** @return string the name of this reader. */
    public function get_reader_name() {
        return $this->readerquiz->get_reader_name();
    }

    /** @return object the course_module object. */
    public function get_cm() {
        return $this->readerquiz->get_cm();
    }

    /** @return object the course_module object. */
    public function get_cmid() {
        return $this->readerquiz->get_cmid();
    }

    /**
     * @return bool wether the current user is someone who previews the reader,
     * rather than attempting it.
     */
    public function is_preview_user() {
        return $this->readerquiz->is_preview_user();
    }

    /** @return int the number of attempts allowed at this reader (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->readerquiz->get_num_attempts_allowed();
    }

    /** @return int number fo pages in this reader. */
    public function get_num_pages() {
        return count($this->pagelayout);
    }

    /**
     * @param int $timenow the current time as a unix timestamp.
     * @return reader_access_manager and instance of the reader_access_manager class
     *      for this reader at this time.
     */
    public function get_access_manager($timenow) {
        return $this->readerquiz->get_access_manager($timenow);
    }

    /** @return int the attempt id. */
    public function get_attemptid() {
        return $this->attempt->id;
    }

    /** @return int the attempt unique id. */
    public function get_uniqueid() {
        return $this->attempt->uniqueid;
    }

    /** @return object the row from the reader_attempts table. */
    public function get_attempt() {
        return $this->attempt;
    }

    /** @return int the number of this attemp (is it this user's first, second, ... attempt). */
    public function get_attempt_number() {
        return $this->attempt->attempt;
    }

    /** @return int the id of the user this attempt belongs to. */
    public function get_userid() {
        return $this->attempt->userid;
    }

    /**
     * @return bool whether this attempt has been finished (true) or is still
     *     in progress (false).
     */
    public function is_finished() {
        return $this->attempt->timefinish != 0;
    }

    /** @return bool whether this attempt is a preview attempt. */
    public function is_preview() {
        return $this->attempt->preview;
    }

    /**
     * Is this a student dealing with their own attempt/teacher previewing,
     * or someone with 'mod/reader:viewreports' reviewing someone elses attempt.
     *
     * @return bool whether this situation should be treated as someone looking at their own
     * attempt. The distinction normally only matters when an attempt is being reviewed.
     */
    public function is_own_attempt() {
        global $USER;
        return (($this->attempt->userid == $USER->id) && (! $this->is_preview_user() || $this->attempt->preview));
    }

    /**
     * @return bool whether this attempt is a preview belonging to the current user.
     */
    public function is_own_preview() {
        global $USER;
        return (($this->attempt->userid == $USER->id) && ($this->is_preview_user() && $this->attempt->preview));
    }

    /**
     * Is the current user allowed to review this attempt. This applies when
     * {@link is_own_attempt()} returns false.
     * @return bool whether the review should be allowed.
     */
    public function is_review_allowed() {
        if (! $this->has_capability('mod/reader:viewreports')) {
            return false;
        }

        $cm = $this->get_cm();
        if ($this->has_capability('moodle/site:accessallgroups') || groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return true;
        }

        // Check the users have at least one group in common.
        $teachersgroups = groups_get_activity_allowed_groups($cm);
        $studentsgroups = groups_get_all_groups(
                $cm->course, $this->attempt->userid, $cm->groupingid);
        return $teachersgroups && $studentsgroups &&
                array_intersect(array_keys($teachersgroups), array_keys($studentsgroups));
    }

    /**
     * Get the overall feedback corresponding to a particular mark.
     * @param $grade a particular grade.
     */
    public function get_overall_feedback($grade) {
        return reader_feedback_for_grade($grade, $this->get_reader(),
                $this->readerquiz->get_context());
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the reader context.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return $this->readerquiz->has_capability($capability, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability funciton that automatically passes in the reader context.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        return $this->readerquiz->require_capability($capability, $userid, $doanything);
    }

    /**
     * Check the appropriate capability to see whether this user may review their own attempt.
     * If not, prints an error.
     */
    public function check_review_capability() {
        $this->require_capability('mod/reader:attempt');
    }

    /**
     * @return int one of the mod_reader_display_options::DURING,
     *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
     */
    public function get_attempt_state() {
        return reader_attempt_state($this->get_reader(), $this->attempt);
    }

    /**
     * Wrapper that the correct mod_reader_display_options for this reader at the
     * moment.
     *
     * @return question_display_options the render options for this user on this attempt.
     */
    public function get_display_options($reviewing) {
        if ($reviewing) {
            if (is_null($this->reviewoptions)) {
                $this->reviewoptions = reader_get_review_options($this->get_reader(),
                        $this->attempt, $this->readerquiz->get_context());
            }
            return $this->reviewoptions;

        } else {
            $options = mod_reader_display_options::make_from_reader($this->get_quiz(),
                    mod_reader_display_options::DURING, $this->readerquiz->course->showgrades);
            $options->flags = 'question_display_options::HIDDEN';
            return $options;
        }
    }

    /**
     * Wrapper that the correct mod_reader_display_options for this reader at the
     * moment.
     *
     * @param bool $reviewing true for review page, else attempt page.
     * @param int $slot which question is being displayed.
     * @param moodle_url $thispageurl to return to after the editing form is
     *      submitted or cancelled. If null, no edit link will be generated.
     *
     * @return question_display_options the render options for this user on this
     *      attempt, with extra info to generate an edit link, if applicable.
     */
    public function get_display_options_with_edit_link($reviewing, $slot, $thispageurl) {
        $options = clone($this->get_display_options($reviewing));

        if (! $thispageurl) {
            return $options;
        }

        if (! ($reviewing || $this->is_preview())) {
            return $options;
        }

        $question = $this->quba->get_question($slot);
        if (! question_has_capability_on($question, 'edit', $question->category)) {
            return $options;
        }

        $options->editquestionparams['cmid'] = $this->get_cmid();
        $options->editquestionparams['returnurl'] = $thispageurl;

        return $options;
    }

    /**
     * @param int $page page number
     * @return bool true if this is the last page of the reader.
     */
    public function is_last_page($page) {
        return $page == count($this->pagelayout) - 1;
    }

    /**
     * Return the list of question ids for either a given page of the reader, or for the
     * whole reader.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the reqested list of question ids.
     */
    public function get_slots($page = 'all') {
        if ($page === 'all') {
            $numbers = array();
            foreach ($this->pagelayout as $numbersonpage) {
                $numbers = array_merge($numbers, $numbersonpage);
            }
            return $numbers;
        } else {
            return $this->pagelayout[$page];
        }
    }

    /**
     * Get the question_attempt object for a particular question in this attempt.
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt
     */
    public function get_question_attempt($slot) {
        return $this->quba->get_question_attempt($slot);
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_real_question($slot) {
        return $this->quba->get_question($slot)->length != 0;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_question_flagged($slot) {
        return $this->quba->get_question_attempt($slot)->is_flagged();
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the reader.
     */
    public function get_question_number($slot) {
        return $this->quba->get_question($slot)->_number;
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the reader.
     */
    public function get_question_name($slot) {
        return $this->quba->get_question($slot)->name;
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguised.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the reader.
     */
    public function get_question_status($slot, $showcorrectness) {
        return $this->quba->get_question_state_string($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguised.
     * @return string class name for this state.
     */
    public function get_question_state_class($slot, $showcorrectness) {
        return $this->quba->get_question_state_class($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question.
     * You must previously have called load_question_states to load the state
     * data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified by the reader.
     */
    public function get_question_mark($slot) {
        return reader_format_question_grade($this->get_reader(), $this->quba->get_question_mark($slot));
    }

    /**
     * Get the time of the most recent action performed on a question.
     * @param int $slot the number used to identify this question within this usage.
     * @return int timestamp.
     */
    public function get_question_action_time($slot) {
        return $this->quba->get_question_action_time($slot);
    }

    /**
     * @return string reader view url.
     */
    public function view_url() {
        return $this->readerquiz->view_url();
    }

    /**
     * @return string the URL of this reader's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($slot = null, $page = -1) {
        if ($page == -1 && !is_null($slot)) {
            $page = $this->quba->get_question($slot)->_page;
        } else {
            $page = 0;
        }
        return $this->readerquiz->start_attempt_url($page);
    }

    /**
     * @param int $slot if speified, the slot number of a specific question to link to.
     * @param int $page if specified, a particular page to link to. If not givem deduced
     *      from $slot, or goes to the first page.
     * @param int $questionid a question id. If set, will add a fragment to the URL
     * to jump to a particuar question on the page.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to continue this attempt.
     */
    public function attempt_url($slot = null, $page = -1, $thispage = -1) {
        return $this->page_and_question_url('attempt', $slot, $page, false, $thispage);
    }

    /**
     * @return string the URL of this reader's summary page.
     */
    public function summary_url() {
        return new moodle_url('/mod/reader/quiz/summary.php', array('attempt' => $this->attempt->id));
    }

    /**
     * @return string the URL of this reader's summary page.
     */
    public function processattempt_url() {
        return new moodle_url('/mod/reader/quiz/processattempt.php');
    }

    /**
     * @param int $slot indicates which question to link to.
     * @param int $page if specified, the URL of this particular page of the attempt, otherwise
     * the URL will go to the first page.  If -1, deduce $page from $slot.
     * @param bool $showall if true, the URL will be to review the entire attempt on one page,
     * and $page will be ignored.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to review this attempt.
     */
    public function review_url($slot = null, $page = -1, $showall = false, $thispage = -1) {
        return $this->page_and_question_url('review', $slot, $page, $showall, $thispage);
    }

    /**
     * Initialise the JS etc. required all the questions on a page..
     * @param mixed $page a page number, or 'all'.
     */
    public function get_html_head_contributions($page = 'all', $showall = false) {
        if ($showall) {
            $page = 'all';
        }
        $result = '';
        foreach ($this->get_slots($page) as $slot) {
            $result .= $this->quba->render_question_head_html($slot);
        }
        $result .= question_engine::initialise_js();
        return $result;
    }

    /**
     * Initialise the JS etc. required by one question.
     * @param int $questionid the question id.
     */
    public function get_question_html_head_contributions($slot) {
        return $this->quba->render_question_head_html($slot) .
                question_engine::initialise_js();
    }

    /**
     * Print the HTML for the start new preview button, if the current user
     * is allowed to see one.
     */
    public function restart_preview_button() {
        global $OUTPUT;
        if ($this->is_preview() && $this->is_preview_user()) {
            return $OUTPUT->single_button(new moodle_url(
                    $this->start_attempt_url(), array('forcenew' => true)),
                    get_string('startnewpreview', 'mod_reader'));
        } else {
            return '';
        }
    }

    /**
     * Generate the HTML that displayes the question in its current state, with
     * the appropriate display options.
     *
     * @param int $id the id of a question in this reader attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question($slot, $reviewing, $thispageurl = null) {
        return $this->quba->render_question($slot,
                $this->get_display_options_with_edit_link($reviewing, $slot, $thispageurl),
                $this->quba->get_question($slot)->_number);
    }

    /**
     * Like {@link render_question()} but displays the question at the past step
     * indicated by $seq, rather than showing the latest step.
     *
     * @param int $id the id of a question in this reader attempt.
     * @param int $seq the seq number of the past state to display.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param string $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question_at_step($slot, $seq, $reviewing, $thispageurl = '') {
        return $this->quba->render_question_at_step($slot, $seq,
                $this->get_display_options($reviewing),
                $this->quba->get_question($slot)->_number);
    }

    /**
     * Wrapper round print_question from lib/questionlib.php.
     *
     * @param int $id the id of a question in this reader attempt.
     */
    public function render_question_for_commenting($slot) {
        $options = $this->get_display_options(true);
        $options->hide_all_feedback();
        $options->manualcomment = question_display_options::EDITABLE;
        return $this->quba->render_question($slot, $options,
                $this->quba->get_question($slot)->_number);
    }

    /**
     * Check wheter access should be allowed to a particular file.
     *
     * @param int $id the id of a question in this reader attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param string $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function check_file_access($slot, $reviewing, $contextid, $component,
            $filearea, $args, $forcedownload) {
        return $this->quba->check_file_access($slot, $this->get_display_options($reviewing),
                $component, $filearea, $args, $forcedownload);
    }

    /**
     * Get the navigation panel object for this attempt.
     *
     * @param $panelclass The type of panel, reader_attempt_nav_panel or reader_review_nav_panel
     * @param $page the current page number.
     * @param $showall whether we are showing the whole reader on one page. (Used by review.php)
     * @return reader_nav_panel_base the requested object.
     */
    public function get_navigation_panel(mod_reader_renderer $output,
             $panelclass, $page, $showall = false) {
        $panel = new $panelclass($this, $this->get_display_options(true), $page, $showall);

        $bc = new block_contents();
        $bc->attributes['id'] = 'mod_reader_navblock';
        $bc->title = get_string('readernavigation', 'mod_reader');
        $bc->content = $output->navigation_panel($panel);
        return $bc;
    }

    /**
     * Given a URL containing attempt={this attempt id}, return an array of variant URLs
     * @param moodle_url $url a URL.
     * @return string HTML fragment. Comma-separated list of links to the other
     * attempts with the attempt number as the link text. The curent attempt is
     * included but is not a link.
     */
    public function links_to_other_attempts(moodle_url $url) {
        $attempts = reader_get_user_attempts($this->get_reader()->id, $this->attempt->userid, 'all');
        if (count($attempts) <= 1) {
            return false;
        }

        $links = new mod_reader_links_to_other_attempts();
        foreach ($attempts as $at) {
            if ($at->id == $this->attempt->id) {
                $links->links[$at->attempt] = null;
            } else {
                $links->links[$at->attempt] = new moodle_url($url, array('attempt' => $at->id));
            }
        }
        return $links;
    }

    // Methods for processing ==================================================

    /**
     * Process all the actions that were submitted as part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modifed
     * time in the database for these actions. If null, will use the current time.
     */
    public function process_all_actions($timestamp) {
        global $DB;

        $this->quba->process_all_actions($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = $timestamp;
        if ($this->attempt->timefinish) {
            $this->attempt->sumgrades = $this->quba->get_total_mark();
        }
        $DB->update_record('reader_attempts', $this->attempt);

        if (! $this->is_preview() && $this->attempt->timefinish) {
            reader_save_best_grade($this->get_reader(), $this->get_userid());
        }
    }

    /**
     * Update the flagged state for all question_attempts in this usage, if their
     * flagged state was changed in the request.
     */
    public function save_question_flags() {
        $this->quba->update_question_flags();
        question_engine::save_questions_usage_by_activity($this->quba);
    }

    /**
     * finish_attempt
     *
     * @uses $DB
     * @uses $USER
     * @param xxx $timestamp
     * @todo Finish documenting this function
     */
    public function finish_attempt($timestamp) {
        global $DB, $USER;

        $this->quba->process_all_actions($timestamp);
        $this->quba->finish_all_questions($timestamp);

        question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = $timestamp;
        $this->attempt->timefinish   = $timestamp;
        $this->attempt->sumgrades    = $this->quba->get_total_mark();
        $this->attempt->percentgrade = round($this->quba->get_total_mark() / $this->readerquiz->quiz->sumgrades * 100);

        if ($this->readerquiz->reader->percentforreading <= $this->attempt->percentgrade) {
            $this->attempt->passed = 'true';
            $passedlog = "Passed";
        } else {
            $this->attempt->passed = 'false';
            $passedlog = "Failed";
        }

        //reader_add_to_log($this->readerquiz->course->id, 'reader', 'finish attempt: '.$this->readerquiz->book->name, "view.php?id={$this->readerquiz->reader->id}", $this->attempt->percentgrade."%/".$this->attempt->passed);

        $logaction = 'view attempt: '.substr($this->readerquiz->book->name, 0, 26); // 40 char limit
        $loginfo   = "readerID {$this->readerquiz->reader->id}; ".
                     "reader quiz {$this->readerquiz->book->id}; ".
                     "{$this->attempt->percentgrade}/{$passedlog}";
        reader_add_to_log($this->readerquiz->course->id, 'reader', $logaction, "view.php?id={$this->attempt->id}", $loginfo);

        $DB->update_record('reader_attempts', $this->attempt);
    }

    /**
     * Print the fields of the comment form for questions in this attempt.
     * @param $slot which question to output the fields for.
     * @param $prefix Prefix to add to all field names.
     */
    public function question_print_comment_fields($slot, $prefix) {
        // Work out a nice title.
        $student = get_record('user', 'id', $this->get_userid());
        $a = new object();
        $a->fullname = fullname($student, true);
        $a->attempt = $this->get_attempt_number();

        question_print_comment_fields($this->quba->get_question_attempt($slot),
                $prefix, $this->get_display_options(true)->markdp,
                get_string('gradingattempt', 'reader_grading', $a));
    }

    /**
     * Get a URL for a particular question on a particular page of the reader.
     * Used by {@link attempt_url()} and {@link review_url()}.
     *
     * @param string $script. Used in the URL like /mod/reader/$script.php
     * @param int $slot identifies the specific question on the page to jump to.
     *      0 to just use the $page parameter.
     * @param int $page -1 to look up the page number from the slot, otherwise
     *      the page number to go to.
     * @param bool $showall if true, return a URL with showall=1, and not page number
     * @param int $thispage the page we are currently on. Links to questions on this
     *      page will just be a fragment #q123. -1 to disable this.
     * @return The requested URL.
     */
    public function page_and_question_url($script, $slot, $page, $showall, $thispage) {
        // Fix up $page
        if ($page == -1) {
            if (! is_null($slot) && !$showall) {
                $page = $this->quba->get_question($slot)->_page;
            } else {
                $page = 0;
            }
        }

        if ($showall) {
            $page = 0;
        }

        // Add a fragment to scroll down to the question.
        $fragment = '';
        if (! is_null($slot)) {
            if ($slot == reset($this->pagelayout[$page])) {
                // First question on page, go to top.
                $fragment = '#';
            } else {
                $fragment = '#q' . $slot;
            }
        }

        // Work out the correct start to the URL.
        if ($thispage == $page) {
            return new moodle_url($fragment);

        } else {
            $url = new moodle_url('/mod/reader/quiz/' . $script . '.php' . $fragment,
                    array('attempt' => $this->attempt->id));
            if ($showall) {
                $url->param('showall', 1);
            } else if ($page > 0) {
                $url->param('page', $page);
            }
            return $url;
        }
    }
}

/**
 * Represents a single link in the navigation panel.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.1
 */
class reader_nav_question_button implements renderable {
    public $id;
    public $number;
    public $stateclass;
    public $statestring;
    public $currentpage;
    public $flagged;
    public $url;
}

/**
 * Represents the navigation panel, and builds a {@link block_contents} to allow
 * it to be output.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
abstract class reader_nav_panel_base {
    /** @var reader_attempt */
    public $readerattempt;
    /** @var question_display_options */
    public $options;
    /** @var integer */
    public $page;
    /** @var boolean */
    public $showall;

    public function __construct(reader_attempt $readerattempt, question_display_options $options, $page, $showall) {
        $this->readerattempt = $readerattempt;
        $this->options = $options;
        $this->page = $page;
        $this->showall = $showall;
    }

    /**
     * get_question_buttons
     *
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_question_buttons() {
        $buttons = array();
        foreach ($this->readerattempt->get_slots() as $slot) {
            $qa = $this->readerattempt->get_question_attempt($slot);
            $showcorrectness = $this->options->correctness && $qa->has_marks();

            $button = new reader_nav_question_button();
            $button->id          = 'readernavbutton' . $slot;
            $button->number      = $qa->get_question()->_number;
            $button->stateclass  = $qa->get_state_class($showcorrectness);
            if (! $showcorrectness && $button->stateclass == 'notanswered') {
                $button->stateclass = 'complete';
            }
            $button->statestring = $qa->get_state_string($showcorrectness);
            $button->currentpage = $qa->get_question()->_page == $this->page;
            $button->flagged     = $qa->is_flagged();
            $button->url         = $this->get_question_url($slot);
            $buttons[] = $button;
        }

        return $buttons;
    }

    /**
     * render_before_button_bits
     *
     * @param xxx mod_reader_renderer
     * @param xxx $output
     * @return xxx
     * @todo Finish documenting this function
     */
    public function render_before_button_bits(mod_reader_renderer $output) {
        return '';
    }

    abstract public function render_end_bits(mod_reader_renderer $output);

    /**
     * render_restart_preview_link
     *
     * @param xxx $output
     * @return xxx
     * @todo Finish documenting this function
     */
    public function render_restart_preview_link($output) {
        if (! $this->readerattempt->is_own_preview()) {
            return '';
        }
        $url = $this->readerattempt->start_attempt_url();
        $url = new moodle_url($url, array('forcenew' => true));
        return $output->restart_preview_button($url);
    }

    public abstract function get_question_url($slot);

    /**
     * user_picture
     *
     * @uses $DB
     * @return xxx
     * @todo Finish documenting this function
     */
    public function user_picture() {
        global $DB;

        if (! $this->readerattempt->get_reader()->showuserpicture) {
            return null;
        }

        $user = $DB->get_record('user', array('id' => $this->readerattempt->get_userid()));
        $userpicture = new user_picture($user);
        $userpicture->courseid = $this->readerattempt->get_courseid();
        return $userpicture;
    }
}

/**
 * Specialisation of {@link reader_nav_panel_base} for the attempt reader page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class reader_attempt_nav_panel extends reader_nav_panel_base {

    /**
     * get_question_url
     *
     * @param xxx $slot
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_question_url($slot) {
        return $this->readerattempt->attempt_url($slot, -1, $this->page);
    }

    /**
     * render_before_button_bits
     *
     * @param xxx mod_reader_renderer
     * @param xxx $output
     * @return xxx
     * @todo Finish documenting this function
     */
    public function render_before_button_bits(mod_reader_renderer $output) {
        return html_writer::tag('div', get_string('navnojswarning', 'mod_reader'),
                array('id' => 'readernojswarning'));
    }

    /**
     * render_end_bits
     *
     * @param xxx mod_reader_renderer
     * @param xxx $output
     * @return xxx
     * @todo Finish documenting this function
     */
    public function render_end_bits(mod_reader_renderer $output) {
        return html_writer::link($this->readerattempt->summary_url(),
                get_string('endtest', 'mod_reader'), array('class' => 'endtestlink')) .
                $output->countdown_timer() .
                $this->render_restart_preview_link($output);
    }
}

/**
 * Specialisation of {@link reader_nav_panel_base} for the review reader page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class reader_review_nav_panel extends reader_nav_panel_base {

    /**
     * get_question_url
     *
     * @param xxx $slot
     * @return xxx
     * @todo Finish documenting this function
     */
    public function get_question_url($slot) {
        return $this->readerattempt->review_url($slot, -1, $this->showall, $this->page);
    }

    /**
     * render_end_bits
     *
     * @param xxx mod_reader_renderer
     * @param xxx $output
     * @return xxx
     * @todo Finish documenting this function
     */
    public function render_end_bits(mod_reader_renderer $output) {
        $html = '';
        if ($this->readerattempt->get_num_pages() > 1) {
            if ($this->showall) {
                $html .= html_writer::link($this->readerattempt->review_url(null, 0, false),
                        get_string('showeachpage', 'mod_reader'));
            } else {
                $html .= html_writer::link($this->readerattempt->review_url(null, 0, true),
                        get_string('showall', 'mod_reader'));
            }
        }
        $html .= $output->finish_review_link($this->readerattempt->view_url());
        $html .= $this->render_restart_preview_link($output);
        return $html;
    }
}

/**
 * reader_clean_layout
 *
 * @param xxx $layout
 * @param xxx $removeemptypages (optional, default=false)
 * @return xxx
 * @todo Finish documenting this function
 */
function reader_clean_layout($layout, $removeemptypages = false) {
    // Remove repeated ','s. This can happen when a restore fails to find the right
    // id to relink to.
    $layout = preg_replace('/,,+/', ',', trim($layout, ','));

    // Remove duplicate question ids
    $layout = explode(',', $layout);
    $cleanerlayout = array();
    $seen = array();
    foreach ($layout as $item) {
        if ($item == 0) {
            $cleanerlayout[] = '0';
        } else if (! in_array($item, $seen)) {
            $cleanerlayout[] = $item;
            $seen[] = $item;
        }
    }

    if ($removeemptypages) {
        // Avoid duplicate page breaks
        $layout = $cleanerlayout;
        $cleanerlayout = array();
        $stripfollowingbreaks = true; // Ensure breaks are stripped from the start.
        foreach ($layout as $item) {
            if ($stripfollowingbreaks && $item == 0) {
                continue;
            }
            $cleanerlayout[] = $item;
            $stripfollowingbreaks = $item == 0;
        }
    }

    // Add a page break at the end if there is none
    if (end($cleanerlayout) !== '0') {
        $cleanerlayout[] = '0';
    }

    return implode(',', $cleanerlayout);
}

/**
 * reader_get_js_module
 *
 * @uses $PAGE
 * @return xxx
 * @todo Finish documenting this function
 */
function reader_get_js_module() {
    global $PAGE;
    return array(
        'name'     => 'mod_quiz',
        'fullpath' => '/mod/quiz/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key', 'core_question_engine'),
        'strings'  => array(
            array('timesup', 'quiz'),
            array('functiondisabledbysecuremode', 'quiz'),
            array('flagged', 'question'),
        ),
    );
}

/** Include required files */
require_once($CFG->dirroot.'/question/engine/lib.php');

/**
 * mod_reader_display_options
 *
 * @copyright  2013 xxx (xxx@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 * @package    mod
 * @subpackage reader
 */
class mod_reader_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * quiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the quiz settings, and a time constant.
     * @param object $quiz the quiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_reader_display_options set up appropriately.
     */
    public static function make_from_reader($quiz, $when, $mark) {
        $options = new self();

        $options->attempt = self::extract($quiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($quiz->reviewcorrectness, $when);

        if ($mark == 1) {
            $options->marks = self::extract($quiz->reviewmarks, $when, self::MARK_AND_MAX, self::MAX_ONLY);
        } else {
            $options->marks = self::extract($quiz->reviewmarks, $when, self::HIDDEN, self::HIDDEN);
        }

        $options->feedback = self::extract($quiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($quiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($quiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($quiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;

        if ($quiz->questiondecimalpoints != -1) {
            $options->markdp = $quiz->questiondecimalpoints;
        } else {
            $options->markdp = $quiz->decimalpoints;
        }

        return $options;
    }

    public static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}
