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
 * @copyright  2025 Deepak Sharma <deepak@palinfocom.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Represents a dictation question.
 */
class qtype_dictation_question extends question_graded_automatically {

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

    /** @var array Array of gap words */
    public $leftaligntext;

    /** @var string Display mode for gaps */
    public $displaymode;

    /** @var string Scoring method: traditional or levenshtein */
    public $scoringmethod;

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
     * Grade the response.
     *
     * @param array $response
     * @return question_classified_response
     */
    public function grade_response(array $response) {
        $totalweight = 0;
        $totalcorrect = 0;
        $gapscores = array();
        for ($i = 0; $i < count($this->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            $correctword = $this->gaps[$i];
            $correctwordwg = $this->gaps[$i][0];

            $studentword = isset($response[$gapkey]) ? $response[$gapkey] : '';
            
            // Calculate word weight based on length
             $scoringmethod = isset($this->scoringmethod) ? $this->scoringmethod : 'levenshtein';
        
            if ($scoringmethod === 'traditional') {
                $wordweight = 1.0;
            }
            else{
                $wordweight = strlen($correctwordwg);
            }
            
            $totalweight += $wordweight;
            
            // Calculate Levenshtein distance score
            $wordscore = $this->calculate_word_score($correctword, $studentword);
            $totalcorrect += $wordscore * $wordweight;
            $gapscores[] = $wordscore;
        }
        
        // Calculate final grade as weighted average
        $grade = $totalweight > 0 ? $totalcorrect / $totalweight : 0;

        $this->record_attempt($response, $gapscores, $grade);
        
        return array($grade, question_state::graded_state_for_fraction($grade));
    }

    /**
     * Calculate word score using either traditional or Levenshtein scoring method.
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
       
        $student =  $this->normalize_answer($student);
        
        // Normalize case for comparison
       // $correct = strtolower(trim($correct));
       // $student = strtolower(trim($student));
         // Normalize student input
        $student = strtolower(trim($student));
        
        // Handle multiple correct answers
        $correctAnswers = is_array($correct) ? $correct : array($correct);
        $bestScore = 0.0;

        foreach ($correctAnswers as $correctWord) {
            $correctWord = $this->normalize_answer($correctWord);
            $correctWord = strtolower(trim($correctWord));
            $score = $this->calculate_single_word_score($correctWord, $student);
            $bestScore = max($bestScore, $score);
            
            // If we get a perfect match, no need to check other alternatives
            if ($score >= 1.0) {
                break;
            }
        }
        
        return $bestScore;
    }

    public function normalize_answer(string $s): string {
        // Normalize line endings
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // 1) Prefer NFKC via intl Normalizer (covers wide range, including full-width)
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KC);
        } else {
            // 2) Fallback: convert to half-width alphanumerics & spaces
            //  - 'a' => alphabets to half-width
            //  - 'n' => numbers to half-width
            //  - 's' => spaces to half-width (converts U+3000 ideographic space)
            //  (Add 'r' if you need prolonged sound mark handling for kana; not needed for A/Z/0-9)
            $s = mb_convert_kana($s, 'ans', 'UTF-8');
        }

        // Case-insensitive compare
        $s = mb_strtolower($s, 'UTF-8');

        // Normalize whitespace: trim ends and collapse runs of spaces/tabs/ideographic spaces
        // (By now, U+3000 should already be half-width via 's', but this is extra-safe.)
        $s = preg_replace('/[\\h\\x{3000}]+/u', ' ', $s);
        $s = trim($s);

        return $s;
    }

    
    /**
     * Calculate score for a single word using the selected scoring method.
     *
     * @param string $correct The correct word
     * @param string $student The student's input
     * @return float Score between 0 and 1
     */
    private function calculate_single_word_score($correct, $student) {
        
        // Check scoring method - default to Levenshtein if not set
        $scoringmethod = isset($this->scoringmethod) ? $this->scoringmethod : 'levenshtein';
        
        if ($scoringmethod === 'traditional') {
            // Traditional scoring: all or nothing
            return ($correct === $student) ? 1.0 : 0.0;
        } else {
            // Levenshtein scoring: partial credit based on similarity
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
                if (is_array($response[$gapkey])) {
                    $parts[] = $response[$gapkey][0];
                }
                else{
                    $parts[] = $response[$gapkey];
                }
              
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
            // Format correct answer(s) for display
            $correctDisplay = is_array($correctword) ? implode(' / ', $correctword) : $correctword;
            
            $feedback[] = array(
                'gap' => $i,
                'correct' => $correctDisplay,
                'student' => $studentword,
                'score' => $wordscore,
                'iscorrect' => $wordscore >= 1.0 // Consider 80% or higher as correct
            );
        }
        
        return $feedback;
    }


    /**
     * Record student attempt for research and analysis purposes.
     *
     * @param array $response Student responses
     * @param array $gapscores Individual gap scores
     * @param float $totalscore Total weighted score
     */
    private function record_attempt(array $response, array $gapscores, $totalscore) {
        global $DB, $USER;
        
        // Only record if we have a valid user and question attempt context
        if (empty($USER->id) || empty($this->id)) {
            return;
        }
        
        // Get the current question attempt ID from the global context
        $attemptid = $this->get_current_attempt_id();
        if (!$attemptid) {
            return;
        }
        
        // Prepare student responses (remove non-gap data)
        $studentresponses = array();
        foreach ($response as $key => $value) {
            if (strpos($key, 'gap_') === 0) {
                $studentresponses[$key] = $value;
            }
        }
        
        // Get play count
        $playcount = isset($response['playcount']) ? intval($response['playcount']) : 0;
        
        // Check if attempt already exists (update vs insert)
        $existingattempt = $DB->get_record('qtype_dictation_attempts', array(
            'questionid' => $this->id,
            'userid' => $USER->id,
            'attemptid' => $attemptid
        ));
        
        $now = time();
        
        if ($existingattempt) {
            // Update existing attempt
            $existingattempt->responses = json_encode($studentresponses);
            $existingattempt->scores = json_encode($gapscores);
            $existingattempt->totalscore = $totalscore;
            $existingattempt->playcount = $playcount;
            $existingattempt->timemodified = $now;
            
            $DB->update_record('qtype_dictation_attempts', $existingattempt);
        } else {
            // Create new attempt record
            $attemptrecord = new stdClass();
            $attemptrecord->questionid = $this->id;
            $attemptrecord->userid = $USER->id;
            $attemptrecord->attemptid = $attemptid;
            $attemptrecord->responses = json_encode($studentresponses);
            $attemptrecord->scores = json_encode($gapscores);
            $attemptrecord->totalscore = $totalscore;
            $attemptrecord->playcount = $playcount;
            $attemptrecord->timecreated = $now;
            $attemptrecord->timemodified = $now;
            
            $DB->insert_record('qtype_dictation_attempts', $attemptrecord);
        }
    }
    
    /**
     * Get the current question attempt ID from the global context.
     *
     * @return int|null The attempt ID or null if not found
     */
    private function get_current_attempt_id() {
        global $PAGE, $DB;
        
        // Method 1: Try to get from page URL parameters
        if (isset($PAGE->url) && $PAGE->url->get_param('attempt')) {
            return intval($PAGE->url->get_param('attempt'));
        }
        
        // Method 2: Try to get from HTTP parameters
        if (isset($_GET['attempt'])) {
            return intval($_GET['attempt']);
        }
        
        if (isset($_POST['attempt'])) {
            return intval($_POST['attempt']);
        }
        
        // Method 3: Try to get from current question usage
        if (isset($PAGE->cm) && $PAGE->cm->id) {
            try {
                $context = context_module::instance($PAGE->cm->id);
                $sql = "SELECT qa.id 
                        FROM {question_attempts} qa
                        JOIN {question_usages} qu ON qu.id = qa.questionusageid
                        WHERE qa.questionid = ? AND qu.contextid = ?
                        ORDER BY qa.timemodified DESC
                        LIMIT 1";
                
                $result = $DB->get_record_sql($sql, array($this->id, $context->id));
                
                if ($result) {
                    return intval($result->id);
                }
            } catch (Exception $e) {
                // Context not available, continue to next method
            }
        }
        
        // Method 4: Generate a unique session-based identifier if no attempt ID is found
        // This ensures we can still track attempts even in preview mode
        if (session_id()) {
            return crc32(session_id() . '_' . $this->id . '_' . time());
        }
        
        return null;
    }
}
