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
 * Serve question type files.
 *
 * @package    qtype_dictation
 * @copyright  2025 Deepak Sharma <deepak@palinfocom.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

    /**
     * Serve the files from the qtype_dictation file areas.
     *
     * @param stdClass $course the course object
     * @param stdClass $cm the course module object
     * @param stdClass $context the context
     * @param string $filearea the name of the file area
     * @param array $args extra arguments (itemid, path)
     * @param bool $forcedownload whether or not force download
     * @param array $options additional options affecting the file serving
     * @return bool false if the file not found, just send the file otherwise and do not return anything
     */
    function qtype_dictation_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
        global $DB, $CFG;

        // Make sure the filearea is one of those used by the plugin.
        if ($filearea !== 'audio') {
            return false;
        }

        // Make sure the user is logged in.
        require_login();

        // Extract the filename / filepath from the $args array.
        $itemid = array_shift($args); // The first item in the $args array.
        $filename = array_pop($args); // The last item in the $args array.
        if (!$args) {
            $filepath = '/'; // $args is empty => the path is '/'
        } else {
            $filepath = '/' . implode('/', $args) . '/'; // $args contains elements of the filepath
        }

        // Retrieve the file from the Files API.
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'qtype_dictation', $filearea, $itemid, $filepath, $filename);
        if (!$file) {
            return false; // The file does not exist.
        }

        // Send the file back to the browser with a cache lifetime of 1 day.
        send_stored_file($file, 86400, 0, $forcedownload, $options);
    }

    /**
     * Add export link to quiz results page for dictation questions.
     * This function is called by the quiz module to add extra actions.
     *
     * @param int $quizid The quiz ID
     * @param stdClass $cm The course module
     * @return string HTML for additional export links
     */
    function qtype_dictation_extend_quiz_results($quizid, $cm) {
        global $DB, $OUTPUT;
        
        // Check if this quiz contains any dictation questions
        $sql = "SELECT COUNT(DISTINCT q.id)
                FROM {quiz_slots} qs
                JOIN {question} q ON q.id = qs.questionid
                WHERE qs.quizid = ? AND q.qtype = 'dictation'";
        
        $dictationcount = $DB->count_records_sql($sql, array($quizid));
        
        if ($dictationcount > 0) {
            // Check if there are any attempts
            $attemptsql = "SELECT COUNT(da.id)
                           FROM {quiz_slots} qs
                           JOIN {question} q ON q.id = qs.questionid
                           JOIN {qtype_dictation_attempts} da ON da.questionid = q.id
                           WHERE qs.quizid = ?";
            
            $attemptcount = $DB->count_records_sql($attemptsql, array($quizid));
            
            if ($attemptcount > 0) {
                $exporturl = new moodle_url('/question/type/dictation/export_quiz.php', 
                    array('quizid' => $quizid));
                
                return html_writer::div(
                    html_writer::tag('h4', get_string('exportquizdata', 'qtype_dictation')) .
                    html_writer::tag('p', get_string('dictationquestionsfound', 'qtype_dictation', $dictationcount)) .
                    html_writer::link($exporturl, 
                        get_string('exportquizcsv', 'qtype_dictation') . " ($attemptcount)",
                        array('class' => 'btn btn-secondary')
                    ),
                    'alert alert-info mt-3'
                );
            }
        }
        
        return '';
    }