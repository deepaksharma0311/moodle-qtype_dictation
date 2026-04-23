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
 * Question definition class for dictation questions.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Represents a dictation question.
 */
class qtype_dictation_question extends question_with_responses {

    /** @var string The transcript text with gaps marked */
    public $transcript;

    /** @var int Maximum number of times audio can be played */
    public $maxplays;

    /** @var bool Whether audio is enabled */
    public $enableaudio;

    /** @var string URL to the audio file */
    public $audiofile;

    /** @var array Array of gap words */
    public $gaps;

    /**
     * Get expected data types for student responses.
     *
     * @return array
     */
    public function get_expected_data() {
        $expected = array();
        
        for ($i = 0; $i < count($this->gaps); $i++) {
            $expected['gap_' . $i] = PARAM_RAW_TRIMMED;
        }
        
        if ($this->enableaudio) {
            $expected['playcount'] = PARAM_INT;
        }
        
        return $expected;
    }

    /**
     * Get correct response for the question.
     *
     * @return array
     */
    public function get_correct_response() {
        $response = array();
        
        for ($i = 0; $i < count($this->gaps); $i++) {
            $response['gap_' . $i] = $this->gaps[$i];
        }
        
        return $response;
    }

    /**
     * Check if response is complete.
     *
     * @param array $response
     * @return bool
     */
    public function is_complete_response(array $response) {
        for ($i = 0; $i < count($this->gaps); $i++) {
            if (!isset($response['gap_' . $i]) || $response['gap_' . $i] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if response is gradable.
     *
     * @param array $response
     * @return bool
     */
    public function is_gradable_response(array $response) {
        for ($i = 0; $i < count($this->gaps); $i++) {
            if (isset($response['gap_' . $i]) && $response['gap_' . $i] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if two responses are the same.
     *
     * @param array $prevresponse
     * @param array $newresponse
     * @return bool
     */
    public function is_same_response(array $prevresponse, array $newresponse) {
        for ($i = 0; $i < count($this->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            $prev = isset($prevresponse[$gapkey]) ? $prevresponse[$gapkey] : '';
            $new = isset($newresponse[$gapkey]) ? $newresponse[$gapkey] : '';
            
            if ($prev !== $new) {
                return false;
            }
        }
        
        // Also check play count if audio is enabled
        if ($this->enableaudio) {
            $prevcount = isset($prevresponse['playcount']) ? $prevresponse['playcount'] : 0;
            $newcount = isset($newresponse['playcount']) ? $newresponse['playcount'] : 0;
            
            if ($prevcount !== $newcount) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Grade the response.
     *
     * @param array $response
     * @return array
     */
    public function grade_response(array $response) {
        $totalweight = 0;
        $totalcorrect = 0;
        
        for ($i = 0; $i < count($this->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            $correctword = $this->gaps[$i];
            $studentword = isset($response[$gapkey]) ? $response[$gapkey] : '';
            
            // Calculate word weight based on length
            $wordweight = strlen($correctword);
            $totalweight += $wordweight;
            
            // Calculate Levenshtein distance score
            $wordscore = $this->calculate_word_score($correctword, $studentword);
            $totalcorrect += $wordscore * $wordweight;
        }
        
        // Calculate final grade as weighted average
        $grade = $totalweight > 0 ? $totalcorrect / $totalweight : 0;
        
        return array($grade, question_state::graded_state_for_fraction($grade));
    }

    /**
     * Calculate word score using normalized Levenshtein distance.
     *
     * @param string $correct The correct word
     * @param string $student The student's input
     * @return float Score between 0 and 1
     */
    private function calculate_word_score($correct, $student) {
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

    /**
     * Get response summary for display.
     *
     * @param array $response
     * @return string
     */
    public function summarise_response(array $response) {
        $parts = array();
        
        for ($i = 0; $i < count($this->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            if (isset($response[$gapkey]) && $response[$gapkey] !== '') {
                $parts[] = $response[$gapkey];
            }
        }
        
        return implode(', ', $parts);
    }

    /**
     * Get validation error message.
     *
     * @param array $response
     * @return string
     */
    public function get_validation_error(array $response) {
        if (!$this->is_gradable_response($response)) {
            return get_string('pleaseenterananswer', 'qtype_dictation');
        }
        return '';
    }

    /**
     * Get detailed feedback for each gap.
     *
     * @param array $response
     * @return array
     */
    public function get_gap_feedback(array $response) {
        $feedback = array();
        
        for ($i = 0; $i < count($this->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            $correctword = $this->gaps[$i];
            $studentword = isset($response[$gapkey]) ? $response[$gapkey] : '';
            
            $wordscore = $this->calculate_word_score($correctword, $studentword);
            
            $feedback[] = array(
                'gap' => $i,
                'correct' => $correctword,
                'student' => $studentword,
                'score' => $wordscore,
                'iscorrect' => $wordscore >= 0.8 // Consider 80% or higher as correct
            );
        }
        
        return $feedback;
    }
}