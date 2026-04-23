define(['jquery'], function($) {
    'use strict';

    var playCount = 0;
    var maxPlays = 0;
    var enableAudio = true;

    /**
     * Initialize the dictation question functionality.
     *
     * @param {Object} params Configuration parameters
     * @param {number} params.questionid The question ID
     * @param {number} params.maxplays Maximum number of plays allowed
     * @param {boolean} params.enableaudio Whether audio is enabled
     */
    function init(questionid,maxplays,enableaudio, qaid) {
        maxPlays = maxplays || 0;
        
        enableAudio = enableaudio;    
        $( document ).ready(function() {
      
        var questionContainer = $('#q'+questionid);

        if (questionContainer.length === 0) {         
            // Fallback: look for any dictation question container
            questionContainer = $('.qtype_dictation').first();
        }
     
        if (questionContainer.length > 0) {             
            setupAudioPlayer(questionContainer,qaid);
            setupGapFocus(questionContainer);
        }
      });
    }

    /**
     * Set up the audio player functionality.
     *
     * @param {jQuery} container The question container
     */
    function setupAudioPlayer(container,qaid) {
        var audioElement = container.find('audio').first();
        var playButton = container.find('.audio-play-btn');
        var counterElement = container.find('.dictation-play-counter');
        var playCountInput = container.find('input[name*="playcount"]');

        var questionId = container.attr('id') || 'default';
        var isPlaying = false;
        var hasPlayedCurrentSession = false;

        if (audioElement.length === 0 || !enableAudio) {
            // Hide audio controls if no audio or audio disabled
            container.find('.audio-controls').hide();
            return;
        }

        // Get current play count from hidden input
        // if (playCountInput.length > 0 && playCountInput.val()) {
        //     playCount = parseInt(playCountInput.val()) || 0;
        // }

         // Get current play count from multiple sources for robustness
        var storedCount = 0;
        if (playCountInput.length > 0 && playCountInput.val()) {
            storedCount = parseInt(playCountInput.val()) || 0;
        }
        
        // Check localStorage as backup (survives page refresh)
        var localStorageKey = 'dictation_playcount_' + questionId+ '_' + qaid;
        var localStorageCount = parseInt(localStorage.getItem(localStorageKey)) || 0;
        
        // Use the higher count to prevent bypassing limits
        playCount = Math.max(storedCount, localStorageCount);
        
        // Update both storage methods immediately
        updatePlayCount();

        updateButtonState();
        updateCounter();

        // Play button click handler
        playButton.on('click', function(e) {
            e.preventDefault();
            if (canPlay()) {
                playAudio();
            }
        });

        // Audio ended handler
        // audioElement.on('ended', function() {
        //     playCount++;
        //     updatePlayCount();
        //     updateButtonState();
        //     updateCounter();
        // });

        // Audio play handler - increment count immediately when play starts
        audioElement.on('play', function() {
            if (!hasPlayedCurrentSession) {
                playCount++;
                hasPlayedCurrentSession = true;
                isPlaying = true;
                
                // Update count immediately to prevent refresh bypassing
                updatePlayCount();
                updateButtonState();
                updateCounter();
            }
        });

        // Audio ended handler - reset session flag
        audioElement.on('ended', function() {
            hasPlayedCurrentSession = false;
            isPlaying = false;
        });

        // Audio pause handler - reset session flag
        audioElement.on('pause', function() {
            hasPlayedCurrentSession = false;
            isPlaying = false;
        });


        // Audio error handler
        audioElement.on('error', function() {
            console.error('Audio failed to load');
            playButton.prop('disabled', true).text('Audio Error');
            hasPlayedCurrentSession = false;
            isPlaying = false;
        });

        // Handle page unload to save state
        window.addEventListener('beforeunload', function() {
            if (isPlaying) {
                // Ensure count is saved if user closes/refreshes during playback
                localStorage.setItem(localStorageKey, playCount.toString());
            }
        });

        // Clean up old localStorage entries (older than 24 hours)
        function cleanupOldPlayCounts() {
            var keys = Object.keys(localStorage);
            var cutoffTime = Date.now() - (24 * 60 * 60 * 1000); // 24 hours ago
            
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                if (key.startsWith('dictation_playcount_') && key !== localStorageKey) {
                    var timestamp = localStorage.getItem(key + '_timestamp');
                    if (!timestamp || parseInt(timestamp) < cutoffTime) {
                        localStorage.removeItem(key);
                        localStorage.removeItem(key + '_timestamp');
                    }
                }
            }
        }
        
        // Set timestamp for current session
        localStorage.setItem(localStorageKey + '_timestamp', Date.now().toString());
        cleanupOldPlayCounts();


        function canPlay() {
            if(maxPlays==0){
                return true;
            }
            if(playCount < maxPlays){
                return true;
            }
            return false;
        }

        function playAudio() {
            if (audioElement[0] && canPlay()) {
                audioElement[0].play().catch(function(error) {
                    console.error('Audio play failed:', error);
                });
            }
        }

        function updateButtonState() {
            if (!canPlay()) {
                playButton.prop('disabled', true);
                playButton.find('.btn-text').text('Play limit reached');
            } else {
                playButton.prop('disabled', false);
                playButton.find('.btn-text').text('Play Audio');
            }
        }

        function updateCounter() {
            if (counterElement.length > 0) {
                if (maxPlays > 0) {
                    counterElement.text(playCount + ' / ' + maxPlays);
                } else {
                    counterElement.text(playCount);
                }
            }
        }

        function updatePlayCount() {
            if (playCountInput.length > 0) {
                playCountInput.val(playCount);
            }
            // Also save to localStorage for persistence across page refreshes
            localStorage.setItem(localStorageKey, playCount.toString());
        }
    }

    /**
     * Set up gap input focus functionality.
     *
     * @param {jQuery} container The question container
     */
    function setupGapFocus(container) {
        var gapInputs = container.find('input[type="text"][name*="gap"]');
        
        gapInputs.on('focus', function() {
            $(this).addClass('gap-focused');
        });

        gapInputs.on('blur', function() {
            $(this).removeClass('gap-focused');
        });

        // Auto-advance to next gap on Enter (optional enhancement)
        gapInputs.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var currentIndex = gapInputs.index(this);
                var nextInput = gapInputs.eq(currentIndex + 1);
                if (nextInput.length > 0) {
                    nextInput.focus();
                }
            }
        });
    }

    return {
        init: init
    };
});