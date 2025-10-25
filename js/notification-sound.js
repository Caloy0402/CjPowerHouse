/**
 * Universal Notification Sound System
 * Works across all devices and browsers with fallback support
 */

class NotificationSound {
    constructor(options = {}) {
        this.options = {
            soundFile: options.soundFile || 'uploads/NofiticationCash.mp3',
            volume: options.volume || 1.0,
            enableMute: options.enableMute !== false,
            enableTest: options.enableTest !== false,
            storageKey: options.storageKey || 'notificationSoundSettings',
            ...options
        };
        
        this.isMuted = this.loadMuteState();
        this.audioUnlocked = false;
        this.audioElement = null;
        this.audioContext = null;
        
        this.init();
    }
    
    init() {
        this.createAudioElement();
        this.setupEventListeners();
        this.unlockAudioOnInteraction();
    }
    
    createAudioElement() {
        // Remove existing audio element if it exists
        const existingAudio = document.getElementById('universalNotificationSound');
        if (existingAudio) {
            existingAudio.remove();
        }
        
        // Create new audio element
        this.audioElement = document.createElement('audio');
        this.audioElement.id = 'universalNotificationSound';
        this.audioElement.preload = 'auto';
        this.audioElement.volume = this.options.volume;
        
        // Add source
        const source = document.createElement('source');
        source.src = this.options.soundFile;
        source.type = 'audio/mpeg';
        this.audioElement.appendChild(source);
        
        // Add fallback text
        this.audioElement.appendChild(document.createTextNode('Your browser does not support the audio element.'));
        
        // Append to body (hidden)
        this.audioElement.style.display = 'none';
        document.body.appendChild(this.audioElement);
        
        // Handle audio events
        this.audioElement.addEventListener('canplaythrough', () => {
            console.log('Notification sound loaded and ready');
        });
        
        this.audioElement.addEventListener('error', (e) => {
            console.warn('Notification sound failed to load:', e);
        });
    }
    
    setupEventListeners() {
        // Listen for page visibility changes to unlock audio
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !this.audioUnlocked) {
                this.unlockAudio();
            }
        });
        
        // Listen for focus events
        window.addEventListener('focus', () => {
            if (!this.audioUnlocked) {
                this.unlockAudio();
            }
        });
    }
    
    unlockAudioOnInteraction() {
        const unlockEvents = ['click', 'touchstart', 'keydown', 'scroll'];
        
        const unlockHandler = () => {
            this.unlockAudio();
            // Remove listeners after first successful unlock
            unlockEvents.forEach(event => {
                document.removeEventListener(event, unlockHandler);
            });
        };
        
        unlockEvents.forEach(event => {
            document.addEventListener(event, unlockHandler, { once: true });
        });
    }
    
    unlockAudio() {
        if (this.audioUnlocked || !this.audioElement) return;
        
        try {
            // Try to unlock audio context
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                this.audioContext = new AudioContext();
                if (this.audioContext.state === 'suspended') {
                    this.audioContext.resume().then(() => {
                        console.log('AudioContext resumed successfully');
                    }).catch(error => {
                        console.log('AudioContext resume failed:', error);
                    });
                }
            }
            
            // Try to play and immediately pause to unlock audio
            const playPromise = this.audioElement.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    this.audioElement.pause();
                    this.audioElement.currentTime = 0;
                    this.audioUnlocked = true;
                    console.log('Notification sound unlocked successfully');
                }).catch(error => {
                    console.log('Audio unlock failed:', error);
                    // Don't give up - try again on next user interaction
                    this.audioUnlocked = false;
                });
            }
        } catch (error) {
            console.log('Audio unlock error:', error);
            this.audioUnlocked = false;
        }
    }
    
    play() {
        if (this.isMuted || !this.audioElement) {
            return false;
        }
        
        try {
            // Reset audio to beginning
            this.audioElement.currentTime = 0;
            
            // Check if audio is unlocked, if not try to unlock first
            if (!this.audioUnlocked) {
                console.log('Audio not unlocked, attempting to unlock...');
                this.unlockAudio();
                // If still not unlocked, we can't play
                if (!this.audioUnlocked) {
                    console.log('Cannot play sound - audio not unlocked. User interaction required.');
                    return false;
                }
            }
            
            // Play the sound
            const playPromise = this.audioElement.play();
            
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    console.log('Notification sound played successfully');
                    this.triggerVisualFeedback();
                }).catch(error => {
                    console.log('Notification sound play failed:', error);
                    this.handlePlayError(error);
                });
            }
            
            return true;
        } catch (error) {
            console.log('Error playing notification sound:', error);
            this.handlePlayError(error);
            return false;
        }
    }
    
    handlePlayError(error) {
        // Try to unlock audio for next time
        if (!this.audioUnlocked) {
            this.unlockAudio();
        }
        
        // Show user-friendly message for autoplay policy
        if (error.name === 'NotAllowedError') {
            this.showToast('Click anywhere on the page to enable notification sounds', 'info');
        }
    }
    
    triggerVisualFeedback() {
        // Find notification icon and animate it
        const notificationIcon = document.querySelector('.notification-icon, .fa-bell, [data-notification-icon]');
        if (notificationIcon) {
            notificationIcon.style.transform = 'scale(1.2)';
            notificationIcon.style.transition = 'transform 0.2s ease';
            
            setTimeout(() => {
                notificationIcon.style.transform = 'scale(1)';
            }, 200);
        }
    }
    
    toggleMute() {
        this.isMuted = !this.isMuted;
        this.saveMuteState();
        
        // Mute status changed - no need to show toast
        
        return this.isMuted;
    }
    
    setMuted(muted) {
        this.isMuted = muted;
        this.saveMuteState();
    }
    
    testSound() {
        if (this.isMuted) {
            return false;
        }
        
        return this.play();
    }
    
    loadMuteState() {
        try {
            const saved = localStorage.getItem(this.options.storageKey);
            return saved ? JSON.parse(saved).muted : false;
        } catch (error) {
            console.log('Error loading mute state:', error);
            return false;
        }
    }
    
    saveMuteState() {
        try {
            const settings = { muted: this.isMuted };
            localStorage.setItem(this.options.storageKey, JSON.stringify(settings));
        } catch (error) {
            console.log('Error saving mute state:', error);
        }
    }
    
    showToast(message, type = 'info') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `notification-toast notification-toast-${type}`;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#28a745' : type === 'warning' ? '#ffc107' : type === 'error' ? '#dc3545' : '#17a2b8'};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            font-size: 14px;
            font-weight: 500;
            max-width: 300px;
            word-wrap: break-word;
            animation: slideInRight 0.3s ease;
        `;
        
        toast.textContent = message;
        document.body.appendChild(toast);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }
        }, 3000);
    }
    
    // Public API methods
    getMuted() {
        return this.isMuted;
    }
    
    getAudioUnlocked() {
        return this.audioUnlocked;
    }
    
    // Manual unlock method for user interaction
    unlockAudioManually() {
        console.log('Manual audio unlock requested');
        this.unlockAudio();
        return this.audioUnlocked;
    }
    
    // Check if audio is ready to play
    isAudioReady() {
        return this.audioElement && this.audioElement.readyState >= 2 && this.audioUnlocked;
    }
    
    destroy() {
        if (this.audioElement && this.audioElement.parentNode) {
            this.audioElement.parentNode.removeChild(this.audioElement);
        }
        this.audioElement = null;
        this.audioContext = null;
    }
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = NotificationSound;
}

// Make available globally
window.NotificationSound = NotificationSound;
