document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortSelect');
    const tableBody = document.getElementById('applicantsTableBody');
    const filterForm = document.getElementById('filterForm');
    let currentPage = 1;

    const spinnerHTML = `
        <tr>
          <td colspan="5" class="text-center">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </td>
        </tr>
    `;

    function loadApplicants(page = 1) {
        currentPage = page;
        const search = searchInput.value;
        const sort = sortSelect.value;
        tableBody.innerHTML = spinnerHTML;

        fetch(`manage_applicants.php?ajax=1&page=${page}&search_surname=${encodeURIComponent(search)}&sort=${sort}`)
            .then(response => response.text())
            .then(data => {
                tableBody.innerHTML = data;

                // Fade-in rows
                const rows = tableBody.querySelectorAll('tr');
                rows.forEach((row, index) => {
                    setTimeout(() => row.classList.add('show'), 50 * index);
                });

                // Rebind pagination clicks
                document.querySelectorAll('.page-link').forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const page = parseInt(link.getAttribute('data-page'));
                        if (!isNaN(page)) loadApplicants(page);
                    });
                });

                // Reinitialize modals (Bootstrap 5)
                reinitModals();
            })
            .catch(() => {
                tableBody.innerHTML = "<tr><td colspan='5' class='text-center text-danger'>Error loading data.</td></tr>";
            });
    }

    function reinitModals() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            new bootstrap.Modal(modal); // reattach Bootstrap modal behavior
        });
    }

    // Fallback for manual filter submit
    filterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loadApplicants(1);
    });

    // Initial load
    loadApplicants();

    // Instant search & sort
    searchInput.addEventListener('keyup', () => loadApplicants(1));
    sortSelect.addEventListener('change', () => loadApplicants(1));
});
