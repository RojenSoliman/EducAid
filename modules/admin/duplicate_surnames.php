<?php
require_once __DIR__ . '/../../includes/CSRFProtection.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';
// Minimal page that loads assets and uses an AJAX endpoint to fetch data

// --- Inline API handlers (merge get_schools.php, get_municipalities.php, get_duplicate_surnames.php) ---
if (isset($_GET['api'])) {
    // All API routes require an admin session
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['admin_username'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $api = $_GET['api'];

    // Helper: check table exists
    $tableExists = function($table) use ($connection) {
        $chk = @pg_query($connection, "SELECT to_regclass('public." . pg_escape_string($table) . "') AS reg");
        if (!$chk) return false;
        $reg = pg_fetch_result($chk, 0, 'reg');
        pg_free_result($chk);
        return !empty($reg);
    };

    if ($api === 'schools') {
        if (!$tableExists('schools')) { echo json_encode([]); exit; }
        $res = @pg_query($connection, "SELECT school_id, name FROM schools ORDER BY name LIMIT 1000");
        $rows = $res ? pg_fetch_all($res) : [];
        echo json_encode($rows ?: []);
        exit;
    }

    if ($api === 'municipalities') {
        if (!$tableExists('municipalities')) { echo json_encode([]); exit; }
        $res = @pg_query($connection, "SELECT municipality_id, name FROM municipalities ORDER BY name LIMIT 1000");
        $rows = $res ? pg_fetch_all($res) : [];
        echo json_encode($rows ?: []);
        exit;
    }

    if ($api === 'barangays') {
        if (!$tableExists('barangays')) { echo json_encode([]); exit; }
        $res = @pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name LIMIT 1000");
        $rows = $res ? pg_fetch_all($res) : [];
        echo json_encode($rows ?: []);
        exit;
    }

    if ($api === 'badge_count') {
        // Return the number of surname groups with more than one member
        if (!$tableExists('students')) { echo json_encode(['count' => 0]); exit; }
        $countRes = @pg_query($connection, "SELECT COUNT(*) AS c FROM (SELECT LOWER(last_name) FROM students GROUP BY LOWER(last_name) HAVING COUNT(*) > 1) t");
        $count = 0;
        if ($countRes) {
            $count = (int) pg_fetch_result($countRes, 0, 'c');
            pg_free_result($countRes);
        }
        echo json_encode(['count' => $count]);
        exit;
    }

    if ($api === 'rows') {
        // Collect and sanitize filters
        $surname = trim((string)($_GET['surname'] ?? ''));
        $school = trim((string)($_GET['school'] ?? ''));
    $municipality = trim((string)($_GET['municipality'] ?? ''));
    $barangay = trim((string)($_GET['barangay'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $date_from = trim((string)($_GET['date_from'] ?? ''));
        $date_to = trim((string)($_GET['date_to'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = (int)($_GET['per_page'] ?? 20);
        if ($per_page <= 0) $per_page = 20;
        if ($per_page > 500) $per_page = 500; // hard cap
        $offset = ($page - 1) * $per_page;

        // detect auxiliary tables
        $has_schools = $tableExists('schools');
    $has_municipalities = $tableExists('municipalities');
    $has_barangays = $tableExists('barangays');

        $selectSchool = $has_schools ? "COALESCE(s.name,'') AS school" : "'' AS school";
    $selectMunicipality = $has_municipalities ? "COALESCE(m.name,'') AS municipality" : "'' AS municipality";
    $selectBarangay = $has_barangays ? "COALESCE(b.name,'') AS barangay" : "'' AS barangay";
    $joinSchool = $has_schools ? " LEFT JOIN schools s ON s.school_id = f.school_id" : "";
    $joinMunicipality = $has_municipalities ? " LEFT JOIN municipalities m ON m.municipality_id = f.municipality_id" : "";
    $joinBarangay = $has_barangays ? " LEFT JOIN barangays b ON b.barangay_id = f.barangay_id" : "";

        // Build WHERE clause with parameterized values
        $where = [];
        $params = [];

        if ($surname !== '') {
            $params[] = '%' . mb_strtolower($surname) . '%';
            $where[] = "LOWER(last_name) LIKE $" . count($params);
        }
        if ($school !== '') {
            $params[] = $school;
            $where[] = "school_id = $" . count($params);
        }
        if ($municipality !== '') {
            $params[] = $municipality;
            $where[] = "municipality_id = $" . count($params);
        }
        if ($barangay !== '') {
            $params[] = $barangay;
            $where[] = "barangay_id = $" . count($params);
        }
        if ($status !== '') {
            $params[] = $status;
            $where[] = "status = $" . count($params);
        }
        if ($date_from !== '') {
            $params[] = $date_from;
            $where[] = "application_date >= $" . count($params);
        }
        if ($date_to !== '') {
            $params[] = $date_to;
            $where[] = "application_date <= $" . count($params);
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        // Count total matching duplicate surnames
        // Use CTE: filtered -> surname_counts -> join
        $countSql = "WITH filtered AS (SELECT * FROM students $whereSql), surname_counts AS (SELECT LOWER(last_name) AS ln FROM filtered GROUP BY LOWER(last_name) HAVING COUNT(*) > 1) SELECT COUNT(*) AS total FROM filtered f JOIN surname_counts sc ON LOWER(f.last_name)=sc.ln";
        $countRes = @pg_query_params($connection, $countSql, $params);
        $total = 0;
        if ($countRes) {
            $total = (int)pg_fetch_result($countRes, 0, 'total');
            pg_free_result($countRes);
        }

        // Now fetch paginated rows
        // Append limit/offset params
        $params_for_rows = $params;
        $params_for_rows[] = $per_page;
        $params_for_rows[] = $offset;
        $limitPlaceholder = '$' . (count($params_for_rows) - 1);
        $offsetPlaceholder = '$' . (count($params_for_rows));

    $rowsSql = "WITH filtered AS (SELECT * FROM students $whereSql), surname_counts AS (SELECT LOWER(last_name) AS ln FROM filtered GROUP BY LOWER(last_name) HAVING COUNT(*) > 1) SELECT f.student_id, f.first_name, f.last_name, $selectSchool, $selectMunicipality, $selectBarangay, f.mobile, f.email FROM filtered f JOIN surname_counts sc ON LOWER(f.last_name)=sc.ln $joinSchool $joinMunicipality $joinBarangay ORDER BY LOWER(f.last_name) ASC, LOWER(f.first_name) ASC LIMIT $limitPlaceholder OFFSET $offsetPlaceholder";

        $rowsRes = @pg_query_params($connection, $rowsSql, $params_for_rows);
        $rows = $rowsRes ? pg_fetch_all($rowsRes) : [];

        // CSV export
        if (isset($_GET['csv']) && $_GET['csv']) {
            // Output CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="duplicate_surnames.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['First Name','Surname','School','Municipality','Barangay','Phone','Email']);
            if ($rows) {
                foreach ($rows as $r) {
                    fputcsv($out, [$r['first_name'] ?? '', $r['last_name'] ?? '', $r['school'] ?? '', $r['municipality'] ?? '', $r['barangay'] ?? '', $r['mobile'] ?? '', $r['email'] ?? '']);
                }
            }
            exit;
        }

        // Return JSON
        echo json_encode([
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'rows' => $rows ?: [],
        ]);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Duplicate Surnames - Admin</title>
    <link href="/EducAid/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/EducAid/assets/css/bootstrap-icons.css" rel="stylesheet">
    <!-- Admin styles (sidebar, header, layout) -->
    <link href="/EducAid/assets/css/admin/homepage.css" rel="stylesheet">
    <link href="/EducAid/assets/css/admin/sidebar.css" rel="stylesheet">
    <script src="/EducAid/assets/js/admin/sidebar.js" defer></script>
    <script src="/EducAid/assets/js/admin/notification_bell.js" defer></script>
    <style>
    .table-fixed { width:100%; table-layout:fixed }
    .table-fixed td { overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
    /* Slightly smaller table font to show more content and reduce need to scroll */
    .table-container table, .table-container th, .table-container td { font-size: 0.92rem; }
        /* Horizontal scrolling wrapper so wide tables show a scrollbar */
        .table-container { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        /* Default min width to avoid overly compressed columns on large screens */
        .table-container table { min-width:1100px; }

        /* Responsive tuning: reduce min-width at narrower breakpoints */
        @media (max-width: 1400px) {
            .table-container table { min-width:1000px; }
        }
        @media (max-width: 1200px) {
            .table-container table { min-width:900px; }
        }
        @media (max-width: 992px) {
            .table-container table { min-width:700px; }
            /* On smaller screens allow table layout to auto-size and wrap cells */
            .table-fixed { table-layout:auto; }
            .table-fixed td { white-space:normal; }
        }
        @media (max-width: 576px) {
            .table-container table { min-width:560px; }
        }

        /* Visual cue that horizontal scroll is available (subtle) */
        .table-container::after {
            content: '';
            display: block;
            height: 6px;
            margin-top: -6px;
            pointer-events: none;
            background: linear-gradient(90deg, rgba(0,0,0,0.06), rgba(0,0,0,0));
        }
        /* On medium+ screens, size the table to its content so horizontal scrolling reveals full cells (emails) */
        @media (min-width: 768px) {
            .table-container table { min-width: max-content; }
            /* Ensure the Email column is wide enough to show full addresses when scrolled */
            #resultsTable th:nth-child(7),
            #resultsTable td:nth-child(7) {
                min-width: 420px; /* increased for longer emails */
                white-space: nowrap;
            }
        }
        /* Positioning for copy-badge inside email cells */
        #resultsTable td { position: relative; }
        .copied-badge { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.85); color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px; opacity: 0; transition: opacity .16s ease; pointer-events: none; }
        .copied-badge.show { opacity: 1; }
    </style>
</head>
<body class="p-3">
        <?php include_once __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
        <?php include_once __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>

        <style>
            /* Adjust main content to account for fixed topbar and sidebar */
            .admin-main {
                margin-top: 48px; /* space for admin topbar */
                margin-left: 260px; /* space for sidebar; matches sidebar styles */
                padding: 1rem;
            }
            @media (max-width: 991.98px) {
                .admin-main { margin-left: 0; }
            }
        </style>

        <div class="admin-main">
        <div class="container-fluid">
            <h1 class="visually-hidden">Duplicate Surnames</h1>

        <div class="card mb-3">
            <div class="card-body">
                <form id="filterForm" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label">Surname</label>
                        <input name="surname" id="surname" class="form-control" placeholder="Search surname">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label">School</label>
                        <select id="school" name="school" class="form-select"><option value="">All</option></select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label">Municipality</label>
                        <select id="municipality" name="municipality" class="form-select"><option value="">All</option></select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label">Barangay</label>
                        <select id="barangay" name="barangay" class="form-select"><option value="">All</option></select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All</option>
                            <option value="applicant">Applicant</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label">Date From</label>
                        <input type="date" id="date_from" name="date_from" class="form-control">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label">Date To</label>
                        <input type="date" id="date_to" name="date_to" class="form-control">
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button id="applyBtn" class="btn btn-primary">Apply</button>
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button id="exportCsvBtn" class="btn btn-outline-secondary">Export CSV</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="mb-2 d-flex justify-content-between align-items-center">
            <div id="summary"></div>
            <div>
                <label class="me-2">Per page</label>
                <select id="per_page" class="form-select form-select-sm" style="width:6rem; display:inline-block">
                    <option>20</option><option>50</option><option>100</option>
                </select>
            </div>
        </div>

        <div class="table-container table-responsive">
            <table class="table table-striped table-fixed" id="resultsTable">
                <thead>
                    <tr>
                        <th data-key="first_name">First Name</th>
                        <th data-key="last_name">Surname</th>
                        <th data-key="school">School</th>
                        <th data-key="municipality">Municipality</th>
                        <th data-key="barangay">Barangay</th>
                        <th data-key="mobile">Phone Number</th>
                        <th data-key="email">Email</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination" id="pagination"></ul>
        </nav>
    </div>

    <script src="/EducAid/assets/js/bootstrap.bundle.min.js"></script>
    <script>
    // Minimal client-side: fetch schools and municipalities, then request results
    const endpoint = 'duplicate_surnames.php';

        async function loadSelects(){
            // schools
            const sRes = await fetch(endpoint + '?api=schools').then(r=>r.json()).catch(()=>[]);
            const schoolEl = document.getElementById('school');
            if (Array.isArray(sRes)) sRes.forEach(s=>{ const o=document.createElement('option'); o.value=s.school_id; o.textContent=s.name; schoolEl.appendChild(o); });
            // municipalities
            const mRes = await fetch(endpoint + '?api=municipalities').then(r=>r.json()).catch(()=>[]);
            const muniEl = document.getElementById('municipality');
            if (Array.isArray(mRes)) mRes.forEach(m=>{ const o=document.createElement('option'); o.value=m.municipality_id; o.textContent=m.name; muniEl.appendChild(o); });
            // barangays
            const bRes = await fetch(endpoint + '?api=barangays').then(r=>r.json()).catch(()=>[]);
            const barangayEl = document.getElementById('barangay');
            if (Array.isArray(bRes)) bRes.forEach(b=>{ const o=document.createElement('option'); o.value=b.barangay_id; o.textContent=b.name; barangayEl.appendChild(o); });
        }

        function readFilters(){
            const f = new FormData(document.getElementById('filterForm'));
            return Object.fromEntries(f.entries());
        }

        async function fetchData(page=1){
            const perPage = document.getElementById('per_page').value || 20;
            const filters = readFilters();
            filters.page = page; filters.per_page = perPage;
            const params = new URLSearchParams(filters);
            const res = await fetch(endpoint + '?api=rows&' + params.toString());
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (err) {
                console.error('Invalid JSON response from server:', text);
                document.getElementById('summary').textContent = 'Server returned invalid response (check console)';
                return;
            }
            renderTable(data);
        }

        function renderTable(data){
            const tbody = document.querySelector('#resultsTable tbody'); tbody.innerHTML='';
            document.getElementById('summary').textContent = `${data.total || 0} records`;
            (data.rows||[]).forEach(r=>{
                const tr = document.createElement('tr');
                // Use data-email attribute for copy-on-click and keep title for native tooltip fallback
                tr.innerHTML = `<td>${escapeHtml(r.first_name)}</td><td>${escapeHtml(r.last_name)}</td><td>${escapeHtml(r.school)}</td><td>${escapeHtml(r.municipality)}</td><td>${escapeHtml(r.barangay)}</td><td>${escapeHtml(r.mobile)}</td><td data-email="${escapeHtml(r.email)}" title="${escapeHtml(r.email)}">${escapeHtml(r.email)}</td>`;
                tbody.appendChild(tr);

                // Attach copy-on-click handler to email cell
                const emailTd = tr.querySelector('td[data-email]');
                if (emailTd) {
                    emailTd.addEventListener('click', async (e) => {
                        const val = emailTd.getAttribute('data-email') || emailTd.textContent;
                        // Try navigator.clipboard first
                        try {
                            await navigator.clipboard.writeText(val);
                        } catch (err) {
                            // Fallback: create a temporary textarea
                            try {
                                const ta = document.createElement('textarea');
                                ta.value = val;
                                document.body.appendChild(ta);
                                ta.select();
                                document.execCommand('copy');
                                document.body.removeChild(ta);
                            } catch (err2) {
                                // ignore copy failure
                            }
                        }

                        // Show small "Copied" badge
                        let badge = emailTd.querySelector('.copied-badge');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'copied-badge';
                            badge.textContent = 'Copied';
                            emailTd.appendChild(badge);
                        }
                        badge.classList.add('show');
                        setTimeout(()=> badge.classList.remove('show'), 1200);
                    });
                }
            });
            // pagination
            const pag = document.getElementById('pagination'); pag.innerHTML='';
            const pages = Math.max(1, Math.ceil((data.total||0)/(data.per_page||20)));
            for(let i=1;i<=pages;i++){ const li=document.createElement('li'); li.className='page-item'+(i===data.page?' active':''); li.innerHTML=`<a class="page-link" href="#">${i}</a>`; li.onclick=(e)=>{e.preventDefault(); fetchData(i)}; pag.appendChild(li); }
        }

        function escapeHtml(s){ return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        document.getElementById('applyBtn').addEventListener('click', function(e){ e.preventDefault(); fetchData(1); });
        document.getElementById('per_page').addEventListener('change', ()=>fetchData(1));
    document.getElementById('exportCsvBtn').addEventListener('click', function(e){ e.preventDefault(); const f=readFilters(); f.csv=1; const p=new URLSearchParams(f); window.location = endpoint + '?api=rows&' + p.toString(); });

        // init
        loadSelects().then(()=>fetchData(1));
    </script>
</body>
</html>
