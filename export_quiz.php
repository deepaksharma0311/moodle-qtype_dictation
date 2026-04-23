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
 * Export entire quiz dictation data for all questions and attempts.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/question/type/dictation/classes/output/export_csv.php');

// Get parameters
$quizid = required_param('quizid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Security checks
require_login();
$quiz = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:viewreports', $context);

// Set up page
$PAGE->set_url('/question/type/dictation/export_quiz.php', array('quizid' => $quizid));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('exportquizdata', 'qtype_dictation'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($quiz->name, new moodle_url('/mod/quiz/view.php', array('id' => $cm->id)));
$PAGE->navbar->add(get_string('exportquizdata', 'qtype_dictation'));

// Handle export action
if ($action === 'export') {
    \qtype_dictation\output\export_csv::export_quiz_data($quizid, $context->id);
    exit;
}

// Display page
echo $OUTPUT->header();

// Get dictation questions in this quiz
$sql = "SELECT DISTINCT q.id, q.name, COUNT(da.id) as attempt_count
        FROM {quiz_slots} qs
        JOIN {question} q ON q.id = qs.questionid
        LEFT JOIN {qtype_dictation_attempts} da ON da.questionid = q.id
        WHERE qs.quizid = ? AND q.qtype = 'dictation'
        GROUP BY q.id, q.name
        ORDER BY qs.slot";

$dictationquestions = $DB->get_records_sql($sql, array($quizid));

echo $OUTPUT->heading(get_string('exportquizdata', 'qtype_dictation') . ': ' . format_string($quiz->name));

if (!empty($dictationquestions)) {
    $totalattempts = 0;
    
    echo html_writer::tag('p', get_string('dictationquestionsfound', 'qtype_dictation', count($dictationquestions)));
    
    // Show questions and attempt counts
    $table = new html_table();
    $table->head = array(
        get_string('question'),
        get_string('attempts', 'qtype_dictation'),
        get_string('actions')
    );
    
    foreach ($dictationquestions as $question) {
        $totalattempts += $question->attempt_count;
        
        $questionurl = new moodle_url('/question/type/dictation/export_attempts.php', 
            array('questionid' => $question->id));
        $questionlink = html_writer::link($questionurl, 
            get_string('exportindividual', 'qtype_dictation'));
        
        $table->data[] = array(
            format_string($question->name),
            $question->attempt_count,
            $questionlink
        );
    }
    
    echo html_writer::table($table);
    
    if ($totalattempts > 0) {
        echo html_writer::tag('div', 
            html_writer::tag('h3', get_string('exportallquizdata', 'qtype_dictation')) .
            html_writer::tag('p', get_string('exportallquizdesc', 'qtype_dictation', $totalattempts)) .
            html_writer::link(
                new moodle_url('/question/type/dictation/export_quiz.php', 
                    array('quizid' => $quizid, 'action' => 'export')),
                get_string('exportquizcsv', 'qtype_dictation'),
                array('class' => 'btn btn-primary btn-lg')
            ),
            array('class' => 'alert alert-success')
        );
    } else {
        echo html_writer::tag('p', get_string('noattemptsfound', 'qtype_dictation'), 
            array('class' => 'alert alert-warning'));
    }
    
} else {
    echo html_writer::tag('p', get_string('nodictationquestions', 'qtype_dictation'), 
        array('class' => 'alert alert-info'));
}

echo $OUTPUT->footer();