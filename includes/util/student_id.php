<?php
// Utility for generating system student IDs in the format:
//   <MUNICIPALITY>-<YEAR>-<YEARLEVEL>-<RANDOM6>
// Where:
//  - MUNICIPALITY is from municipalities.slug (preferred) or name (sanitized to A-Z0-9), fallback MUNI<id>
//  - YEAR is the 4-digit year
//  - YEARLEVEL is digits from year_levels.code or falls back to year_level_id
//  - RANDOM6 is a random 6-character code (A-Z0-9) ensured unique against students.student_id

if (!function_exists('ea_random_code')) {
    function ea_random_code($len = 6) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'; // avoid easily-confused I/O/1
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}

if (!function_exists('ea_sanitize_tag')) {
    function ea_sanitize_tag($s) {
        $s = strtoupper((string)$s);
        return preg_replace('/[^A-Z0-9]/', '', $s) ?: '';
    }
}

if (!function_exists('generateSystemStudentId')) {
    /**
     * Generate a system student ID connected to the students table.
    * Format: <MUNICIPALITY>-<YEAR>-<YEARLEVEL>-<RANDOM6>
     *  - MUNICIPALITY: municipalities.slug (preferred) uppercased and alphanumeric-only;
     *    fallback to municipalities.name sanitized; fallback to 'MUNI' + municipality_id
     *  - YEAR: 4-digit year (current by default)
     *  - YEARLEVEL: digits from year_levels.code or fallback to year_level_id
    *  - RANDOM6: random A-Z0-9, generator ensures uniqueness with DB check
     *
     * @param \PgSql\Connection|resource $connection PostgreSQL connection
     * @param int $year_level_id The student's year_level_id
     * @param int $municipality_id The municipality context
     * @param int|null $year Optional 4-digit year; defaults to current year
     * @return string|null Generated ID or null on failure
     */
    function generateSystemStudentId($connection, $year_level_id, $municipality_id, $year = null) {
        if (!$connection) return null;
        $yearStr = ($year && preg_match('/^\d{4}$/', (string)$year)) ? (string)$year : date('Y');

        // Municipality prefix
        $muni = null;
        $mres = @pg_query_params(
            $connection,
            "SELECT COALESCE(NULLIF(slug,''), name) AS tag FROM municipalities WHERE municipality_id = $1",
            [intval($municipality_id)]
        );
        if ($mres && pg_num_rows($mres) > 0) {
            $mrow = pg_fetch_assoc($mres);
            $muni = ea_sanitize_tag((string)($mrow['tag'] ?? ''));
        }
        if (!$muni) { $muni = 'MUNI' . intval($municipality_id ?: 0); }

        // Determine numeric code for year level
        $code = null;
        $res = @pg_query_params(
            $connection,
            "SELECT COALESCE(NULLIF(regexp_replace(COALESCE(code, ''), '[^0-9]', '', 'g'), ''), year_level_id::text) AS code FROM year_levels WHERE year_level_id = $1",
            [intval($year_level_id)]
        );
        if ($res && pg_num_rows($res) > 0) {
            $row = pg_fetch_assoc($res);
            $code = preg_replace('/[^0-9]/', '', (string)($row['code'] ?? ''));
        }
        if ($code === null || $code === '') {
            $code = (string)intval($year_level_id ?: 0);
        }

        $base = $muni . '-' . $yearStr . '-' . $code . '-';
        // Try multiple random codes to guarantee uniqueness
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $rand6 = ea_random_code(6);
            $candidate = $base . $rand6;
            $chk = @pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1 LIMIT 1", [$candidate]);
            if ($chk && pg_num_rows($chk) === 0) {
                return $candidate;
            }
        }
        // Last resort: extend code length to reduce collision probability
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $rand8 = ea_random_code(8);
            $candidate = $base . $rand8;
            $chk = @pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1 LIMIT 1", [$candidate]);
            if ($chk && pg_num_rows($chk) === 0) {
                return $candidate;
            }
        }
        return null;
    }
}

?>
