/**
 * JavaScript module for dictation question type.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    /**
     * Initialize the dictation question functionality.
     *
     * @param {Object} params Configuration parameters
     */
    function init(params) {
        var questionId = params.questionid;
        var maxPlays = params.maxplays;
        var enableAudio = params.enableaudio;

        if (!enableAudio) {
            return; // No audio functionality needed for C-test mode
        }

        var audioElement = $('#dictation-audio-' + questionId);
        var playButton = $('#dictation-play-btn-' + questionId);
        var counterElement = $('#dictation-counter-' + questionId);
        var playCountInput = $('input[name*="playcount"]');

        if (audioElement.length && playButton.length) {
            setupAudioPlayer(audioElement, playButton, counterElement, playCountInput, maxPlays);
        }
    }

    /**
     * Set up the audio player functionality.
     *
     * @param {jQuery} audioElement The audio element
     * @param {jQuery} playButton The play button
     * @param {jQuery} counterElement The play counter display
     * @param {jQuery} playCountInput The hidden input for play count
     * @param {number} maxPlays Maximum number of plays allowed
     */
    function setupAudioPlayer(audioElement, playButton, counterElement, playCountInput, maxPlays) {
        var currentPlayCount = parseInt(playCountInput.val()) || 0;
        var audio = audioElement[0];

        // Update button state based on play limit
        function updateButtonState() {
            if (maxPlays > 0 && currentPlayCount >= maxPlays) {
                playButton.prop('disabled', true)
                         .addClass('disabled')
                         .text(M.util.get_string('playlimitreached', 'qtype_dictation'));
                audioElement.prop('disabled', true);
                return false;
            }
            return true;
        }

        // Update play counter display
        function updateCounter() {
            if (maxPlays > 0 && counterElement.length) {
                var counterText = M.util.get_string('playcount', 'qtype_dictation', {
                    current: currentPlayCount,
                    max: maxPlays
                });
                counterElement.text(counterText);
            }
        }

        // Initialize state
        updateButtonState();
        updateCounter();

        // Play button click handler
        playButton.on('click', function(e) {
            e.preventDefault();
            
            if (!updateButtonState()) {
                return;
            }

            if (audio.paused) {
                audio.play().then(function() {
                    playButton.text(M.util.get_string('pause', 'qtype_dictation') || 'Pause');
                }).catch(function(error) {
                    console.error('Error playing audio:', error);
                    alert(M.util.get_string('audioerror', 'qtype_dictation') || 'Error playing audio');
                });
            } else {
                audio.pause();
                playButton.text(M.util.get_string('play', 'qtype_dictation') || 'Play');
            }
        });

        // Audio event handlers
        audio.addEventListener('play', function() {
            playButton.text(M.util.get_string('pause', 'qtype_dictation') || 'Pause');
        });

        audio.addEventListener('pause', function() {
            playButton.text(M.util.get_string('play', 'qtype_dictation') || 'Play');
        });

        audio.addEventListener('ended', function() {
            playButton.text(M.util.get_string('play', 'qtype_dictation') || 'Play');
            
            // Increment play count
            currentPlayCount++;
            playCountInput.val(currentPlayCount);
            
            updateButtonState();
            updateCounter();
        });

        // Prevent right-click context menu on audio
        audioElement.on('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        // Keyboard accessibility
        playButton.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });

        // Focus management for gaps
        $('.dictation-gap').on('focus', function() {
            $(this).addClass('focused');
        }).on('blur', function() {
            $(this).removeClass('focused');
        });

        // Auto-advance to next gap on Enter key
        $('.dictation-gap').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var currentIndex = $('.dictation-gap').index(this);
                var nextGap = $('.dictation-gap').eq(currentIndex + 1);
                if (nextGap.length) {
                    nextGap.focus();
                }
            }
        });
    }

    return {
        init: init
    };
});
