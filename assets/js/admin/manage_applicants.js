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
});
