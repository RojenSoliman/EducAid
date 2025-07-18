// Get the elements
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const toggleButton = document.getElementById('toggleSidebar');

// Check the stored sidebar state on page load and apply it
if (localStorage.getItem('sidebarState') === 'collapsed') {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('collapsed');
} else {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('collapsed');
}

// Event listener to toggle sidebar collapse
toggleButton.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('collapsed');

    // Store the current state of the sidebar in localStorage
    if (sidebar.classList.contains('collapsed')) {
        localStorage.setItem('sidebarState', 'collapsed');
    } else {
        localStorage.setItem('sidebarState', 'expanded');
    }
});

// Delay the page navigation until the transition is done
const links = document.querySelectorAll('.sidebar-links a');
links.forEach(link => {
    link.addEventListener('click', (e) => {
        // Prevent immediate page navigation
        e.preventDefault();

        // Add event listener for when the transition ends
        sidebar.addEventListener('transitionend', function handleTransitionEnd() {
            // Remove the event listener to avoid it being triggered multiple times
            sidebar.removeEventListener('transitionend', handleTransitionEnd);

            // After transition ends, proceed with navigation
            window.location.href = e.target.href;
        });
    });
});
