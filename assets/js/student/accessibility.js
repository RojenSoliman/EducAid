// Global Accessibility Settings Loader
// This script should be included on every page to apply saved accessibility preferences

(function() {
  'use strict';

  // Load and apply saved accessibility preferences
  function loadAccessibilityPreferences() {
    // Load saved preferences from localStorage
    const savedTextSize = localStorage.getItem('textSize') || 'normal';
    const savedHighContrast = localStorage.getItem('highContrast') === 'true';
    const savedReduceAnimations = localStorage.getItem('reduceAnimations') === 'true';

    // Apply text size
    document.documentElement.classList.remove('text-small', 'text-normal', 'text-large');
    document.documentElement.classList.add('text-' + savedTextSize);

    // Apply high contrast
    if (savedHighContrast) {
      document.documentElement.classList.add('high-contrast');
    } else {
      document.documentElement.classList.remove('high-contrast');
    }

    // Apply reduced animations
    if (savedReduceAnimations) {
      document.documentElement.classList.add('reduce-animations');
    } else {
      document.documentElement.classList.remove('reduce-animations');
    }
  }

  // Apply immediately (before DOM loads to prevent flash)
  loadAccessibilityPreferences();

  // Also apply when DOM is ready (in case script loads late)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadAccessibilityPreferences);
  }
})();
