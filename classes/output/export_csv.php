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
 * CSV export class for dictation questions.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_dictation\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Handles CSV export of dictation question responses.
 */
class export_csv {

    /**
     * Export dictation responses to CSV format.
     *
     * @param int $questionid The question ID to export data for
     * @param int $contextid The context ID
     * @return void
     */
    public static function export_responses($questionid, $contextid) {
        global $DB, $CFG;

        // Get question details
        $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
        $options = $DB->get_record('qtype_dictation_options', array('questionid' => $questionid), '*', MUST_EXIST);
        $gaps = json_decode($options->gaps, true);

        // Get all attempts for this question with quiz attempt details
        $sql = "SELECT da.id, da.userid, da.attemptid, da.responses, da.scores, da.totalscore, 
                       da.playcount, da.timecreated, da.timemodified,
                       u.firstname, u.lastname, u.email,
                       qa.timestart, qa.timefinish, qa.state AS attempt_state,
                       q.name as quiz_name, qa.sumgrades
                FROM {qtype_dictation_attempts} da
                JOIN {user} u ON u.id = da.userid
                LEFT JOIN {quiz_attempts} qa ON qa.id = da.attemptid
                LEFT JOIN {quiz} q ON q.id = qa.quiz
                WHERE da.questionid = ?
                ORDER BY u.lastname, u.firstname, da.timemodified";

        $attempts = $DB->get_records_sql($sql, array($questionid));

        if (empty($attempts)) {
            print_error('noattempts', 'qtype_dictation');
        }

        // Prepare CSV data
        $csvdata = array();
        
        // Create header row with standard quiz export format
        $header = array(
            'Last name',
            'First name', 
            'Email address',
            'Status',
            'Started',
            'Completed',
            'Duration'
        );

        // Add gap headers in new format: Q{questionid}_Gap{n}_Response | Q{questionid}_Gap{n}_Score | Q{questionid}_Gap{n}_Correct
        for ($i = 0; $i < count($gaps); $i++) {
            $gapnum = $i + 1;
           
           $header[] = "Q{$questionid}_Gap{$gapnum}_Correct";
             $header[] = "Q{$questionid}_Gap{$gapnum}_Response";
               $header[] = "Q{$questionid}_Gap{$gapnum}_Score";
           
        }

        $header[] = 'Total Score';
        $header[] = 'Audio Plays';
        $header[] = 'Quiz Name';
        $header[] = 'Quiz Total Score';

        $csvdata[] = $header;

        // Process each attempt
        foreach ($attempts as $attempt) {
            $row = array();
            
            // Standard quiz export fields
            $row[] = $attempt->lastname;
            $row[] = $attempt->firstname;
            $row[] = $attempt->email;
            
            // Status
            $status = 'finished';
            if (!empty($attempt->attempt_state)) {
                $status = ($attempt->attempt_state == 'finished') ? 'finished' : 'in progress';
            }
            $row[] = $status;
            
            // Started timestamp
            $started = !empty($attempt->timestart) ? userdate($attempt->timestart, '%Y-%m-%d %H:%M:%S') : 
                      userdate($attempt->timecreated, '%Y-%m-%d %H:%M:%S');
            $row[] = $started;
            
            // Completed timestamp  
            $completed = !empty($attempt->timefinish) ? userdate($attempt->timefinish, '%Y-%m-%d %H:%M:%S') : 
                        userdate($attempt->timemodified, '%Y-%m-%d %H:%M:%S');
            $row[] = $completed;
            
            // Duration
            if (!empty($attempt->timestart) && !empty($attempt->timefinish)) {
                $duration = $attempt->timefinish - $attempt->timestart;
                $minutes = floor($duration / 60);
                $seconds = $duration % 60;
                $row[] = sprintf('%d:%02d', $minutes, $seconds);
            } else {
                $row[] = '-';
            }

            // Parse responses and scores from JSON
            $responses = json_decode($attempt->responses, true) ?: array();
            $scores = json_decode($attempt->scores, true) ?: array();
            
            // Process each gap - response, score, and correct answer
            for ($i = 0; $i < count($gaps); $i++) {
                $gapkey = 'gap_' . $i;
                $studentword = isset($responses[$gapkey]) ? $responses[$gapkey] : '';
                $gapscore = isset($scores[$i]) ? $scores[$i] : 0;
                
                // Get correct answer(s) - handle multiple alternatives
                $correctAnswers = is_array($gaps[$i]) ? $gaps[$i] : array($gaps[$i]);
                $correctDisplay = implode(' / ', $correctAnswers);
                 $row[] = $correctDisplay;
                $row[] = $studentword;
                $row[] = round($gapscore, 4);
               
            }

            // Dictation question total score
            $row[] = round($attempt->totalscore, 4);
            
            // Audio play count
            $row[] = $attempt->playcount;
            
            // Quiz name
            $row[] = !empty($attempt->quiz_name) ? format_string($attempt->quiz_name) : 'Question Bank';
            
            // Quiz total score
            $row[] = !empty($attempt->sumgrades) ? round($attempt->sumgrades, 2) : '-';

            $csvdata[] = $row;
        }

        // Generate filename
        $filename = get_string('exportfilename', 'qtype_dictation', date('Y-m-d_H-i-s'));
        
        // Output CSV
        $csvexport = new \csv_export_writer();
        $csvexport->set_filename($filename);
        
        foreach ($csvdata as $row) {
            $csvexport->add_data($row);
        }
        
        $csvexport->download_file();
    }

    /**
     * Export all dictation questions data from a quiz to CSV format.
     *
     * @param int $quizid The quiz ID to export data for
     * @param int $contextid The context ID
     * @return void
     */
    public static function export_quiz_data($quizid, $contextid) {
        global $DB, $CFG;

        // Get quiz details
        $quiz = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);
        
        // Get all dictation questions in this quiz with attempts
        $sql = "SELECT DISTINCT q.id, q.name, qdo.gaps, qs.slot
                FROM {quiz_slots} qs
                JOIN {question} q ON q.id = qs.questionid
                JOIN {qtype_dictation_options} qdo ON qdo.questionid = q.id
                WHERE qs.quizid = ? AND q.qtype = 'dictation'
                ORDER BY qs.slot";

        $questions = $DB->get_records_sql($sql, array($quizid));

        if (empty($questions)) {
            print_error('nodictationquestions', 'qtype_dictation');
        }

        // Get all attempts for these questions
        $questionids = array_keys($questions);
        list($insql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NUMBERED);
        $params[] = $quizid;

        $attemptsql = "SELECT da.id, da.userid, da.questionid, da.attemptid, da.responses, da.scores, 
                              da.totalscore, da.playcount, da.timecreated, da.timemodified,
                              u.firstname, u.lastname, u.email,
                              qa.timestart, qa.timefinish, qa.state AS attempt_state, qa.sumgrades,
                              q.name as question_name, q.id as qid
                       FROM {qtype_dictation_attempts} da
                       JOIN {user} u ON u.id = da.userid
                       JOIN {question} q ON q.id = da.questionid
                       LEFT JOIN {quiz_attempts} qa ON qa.id = da.attemptid AND qa.quiz = ?
                       WHERE da.questionid $insql
                       ORDER BY u.lastname, u.firstname, q.id, da.timemodified";

        $attempts = $DB->get_records_sql($attemptsql, $params);

        if (empty($attempts)) {
            print_error('noattemptsfound', 'qtype_dictation');
        }

        // Prepare CSV data
        $csvdata = array();
        
        // Create comprehensive header row
        $header = array(
            'Last name',
            'First name', 
            'Email address',
            'Status',
            'Started',
            'Completed',
            'Duration',
            'Quiz Total Score',
            'Question Name',
            'Question ID',
            'Question Score'
        );

        // Add gap headers for all questions
        $maxgaps = 0;
        $questiongaps = array();
        foreach ($questions as $question) {
            $gaps = json_decode($question->gaps, true);
            $questiongaps[$question->id] = $gaps;
            $maxgaps = max($maxgaps, count($gaps));
        }

        // Add column headers for maximum number of gaps
        for ($i = 1; $i <= $maxgaps; $i++) {
              $header[] = "Gap{$i}_Correct";
            $header[] = "Gap{$i}_Response";
            
         
            $header[] = "Gap{$i}_Score";
        }

        $header[] = 'Audio Plays';

        $csvdata[] = $header;

        // Process each attempt
        foreach ($attempts as $attempt) {
            $row = array();
            
            // Standard quiz export fields
            $row[] = $attempt->lastname;
            $row[] = $attempt->firstname;
            $row[] = $attempt->email;
            
            // Status
            $status = 'finished';
            if (!empty($attempt->attempt_state)) {
                $status = ($attempt->attempt_state == 'finished') ? 'finished' : 'in progress';
            }
            $row[] = $status;
            
            // Started timestamp
            $started = !empty($attempt->timestart) ? userdate($attempt->timestart, '%Y-%m-%d %H:%M:%S') : 
                      userdate($attempt->timecreated, '%Y-%m-%d %H:%M:%S');
            $row[] = $started;
            
            // Completed timestamp  
            $completed = !empty($attempt->timefinish) ? userdate($attempt->timefinish, '%Y-%m-%d %H:%M:%S') : 
                        userdate($attempt->timemodified, '%Y-%m-%d %H:%M:%S');
            $row[] = $completed;
            
            // Duration
            if (!empty($attempt->timestart) && !empty($attempt->timefinish)) {
                $duration = $attempt->timefinish - $attempt->timestart;
                $minutes = floor($duration / 60);
                $seconds = $duration % 60;
                $row[] = sprintf('%d:%02d', $minutes, $seconds);
            } else {
                $row[] = '-';
            }

            // Quiz total score
            $row[] = !empty($attempt->sumgrades) ? round($attempt->sumgrades, 2) : '-';
            
            // Question info
            $row[] = format_string($attempt->question_name);
            $row[] = $attempt->questionid;
            $row[] = round($attempt->totalscore, 4);

            // Parse responses and scores from JSON
            $responses = json_decode($attempt->responses, true) ?: array();
            $scores = json_decode($attempt->scores, true) ?: array();
            $gaps = $questiongaps[$attempt->questionid];
            
            // Process gaps for this question
            for ($i = 0; $i < $maxgaps; $i++) {
                if ($i < count($gaps)) {
                    $gapkey = 'gap_' . $i;
                    $studentword = isset($responses[$gapkey]) ? $responses[$gapkey] : '';
                    $gapscore = isset($scores[$i]) ? $scores[$i] : 0;
                    
                    // Get correct answer(s)
                    $correctAnswers = is_array($gaps[$i]) ? $gaps[$i] : array($gaps[$i]);
                    $correctDisplay = implode(' / ', $correctAnswers);
                    
                    $row[] = $studentword;
                    $row[] = round($gapscore, 4);
                    $row[] = $correctDisplay;
                } else {
                    // Empty gaps for questions with fewer gaps
                    $row[] = '';
                    $row[] = '';
                    $row[] = '';
                }
            }

            // Audio play count
            $row[] = $attempt->playcount;

            $csvdata[] = $row;
        }

        // Generate filename
        $quizname = preg_replace('/[^a-zA-Z0-9_-]/', '_', $quiz->name);
        $filename = "quiz_{$quizname}_dictation_export_" . date('Y-m-d_H-i-s') . ".csv";
        
        // Output CSV
        $csvexport = new \csv_export_writer();
        $csvexport->set_filename($filename);
        
        foreach ($csvdata as $row) {
            $csvexport->add_data($row);
        }
        
        $csvexport->download_file();
    }



    /**
     * Calculate word score using normalized Levenshtein distance.
     *
     * @param string $correct The correct word
     * @param string $student The student's input
     * @return float Score between 0 and 1
     */
    private static function calculate_word_score($correct, $student) {
        if (empty($correct) && empty($student)) {
            return 1.0;
        }
        
        if (empty($correct) || empty($student)) {
            return 0.0;
        }
        
        // Normalize case for comparison
        $correct = strtolower(trim($correct));
        $student = strtolower(trim($student));
        
        if ($correct === $student) {
            return 1.0;
        }
        
        // Calculate Levenshtein distance
        $distance = levenshtein($correct, $student);
        $maxlength = max(strlen($correct), strlen($student));
        
        // Normalized score: 1 - (distance / max_length)
        return max(0, 1 - ($distance / $maxlength));
    }
}
