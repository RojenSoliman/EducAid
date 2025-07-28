<<<<<<< HEAD
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
=======
document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("filterForm");
    const tableWrapper = document.getElementById("tableWrapper");
    const pagination = document.getElementById("pagination");
    const clearBtn = document.getElementById("clearFiltersBtn");

    function fetchTable(page = 1) {
        const formData = new FormData(form);
        formData.append('page', page);

        fetch('manage_applicants.php', {
            method: "POST",
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.text())
        .then(html => {
            // Parse new HTML for table and pagination
            let temp = document.createElement('div');
            temp.innerHTML = html;

            const table = temp.querySelector('table');
            const pag = temp.querySelector('nav');
            tableWrapper.innerHTML = '';
            if (table) tableWrapper.appendChild(table);
            pagination.innerHTML = '';
            if (pag) pagination.appendChild(pag);

            // Optionally animate rows
            document.querySelectorAll('#applicantsTableBody tr').forEach(row => {
                row.classList.add('animated-row');
                row.addEventListener('animationend', () => {
                    row.classList.remove('animated-row');
                }, { once: true });
            });
        });
    }

    // AJAX filter on input/change
    form.querySelectorAll("input, select").forEach(el => {
        el.oninput = () => fetchTable(1);
        el.onchange = () => fetchTable(1);
    });

    // AJAX pagination click
    pagination.addEventListener('click', function(e) {
        if (e.target.matches('.page-link[data-page]')) {
            e.preventDefault();
            const page = parseInt(e.target.dataset.page);
            if (!isNaN(page)) fetchTable(page);
        }
    });

    // Manual page number
    pagination.addEventListener('change', function(e) {
        if (e.target.id === "manualPage") {
            const page = parseInt(e.target.value);
            if (!isNaN(page)) fetchTable(page);
        }
    });

    // Clear filters
    clearBtn.onclick = function() {
        form.reset();
        fetchTable(1);
    };
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157
});
