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
 * Quiz reports extension for dictation question type.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_dictation\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Extends quiz reports with dictation-specific export functionality.
 */
class quiz_reports_extension {
    
    /**
     * Add dictation export links to quiz reports overview page.
     *
     * @param int $quizid Quiz ID
     * @param object $cm Course module object
     * @param object $context Context object
     * @return string HTML content to add to reports page
     */
    public static function add_export_links($quizid, $cm, $context) {
        global $DB, $OUTPUT;
        
        // Check permissions
        if (!has_capability('mod/quiz:viewreports', $context)) {
            return '';
        }
        
        // Get dictation questions in this quiz
        $sql = "SELECT q.id, q.name, COUNT(da.id) as attempt_count
                FROM {quiz_slots} qs
                JOIN {question} q ON q.id = qs.questionid
                LEFT JOIN {qtype_dictation_attempts} da ON da.questionid = q.id
                WHERE qs.quizid = ? AND q.qtype = 'dictation'
                GROUP BY q.id, q.name
                HAVING COUNT(da.id) > 0
                ORDER BY q.name";
        
        $dictationquestions = $DB->get_records_sql($sql, array($quizid));
        
        if (empty($dictationquestions)) {
            return '';
        }
        
        $totalattempts = array_sum(array_column($dictationquestions, 'attempt_count'));
        
        $content = html_writer::start_div('qtype-dictation-export-section alert alert-success');
        $content .= html_writer::tag('h4', '📊 ' . get_string('exportquizdata', 'qtype_dictation'));
        $content .= html_writer::tag('p', get_string('dictationquestionsfound', 'qtype_dictation', count($dictationquestions)));
        
        // Add individual question export links
        if (count($dictationquestions) > 1) {
            $content .= html_writer::tag('p', get_string('exportindividualoptions', 'qtype_dictation'));
            $content .= html_writer::start_tag('ul');
            
            foreach ($dictationquestions as $question) {
                $exporturl = new moodle_url('/question/type/dictation/export_attempts.php', 
                    array('questionid' => $question->id));
                $content .= html_writer::tag('li', 
                    html_writer::link($exporturl, 
                        format_string($question->name) . " ({$question->attempt_count} attempts)")
                );
            }
            $content .= html_writer::end_tag('ul');
        }
        
        // Add quiz-level export button
        $quizexporturl = new moodle_url('/question/type/dictation/export_quiz.php', 
            array('quizid' => $quizid));
        $content .= html_writer::link($quizexporturl, 
            get_string('exportallquizdata', 'qtype_dictation') . " ($totalattempts attempts)",
            array('class' => 'btn btn-primary btn-lg')
        );
        
        $content .= html_writer::end_div();
        
        return $content;
    }
}