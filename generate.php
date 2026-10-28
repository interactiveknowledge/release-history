<?php
/**
 * Builds static Drupal release-history XML from GitHub Releases.
 *
 * Reads projects.json, writes public/release-history/{project}/8.x
 * Requires env GH_TOKEN (read access to the listed repos' releases).
 *
 * Release conventions:
 *   - Tag names like 1.2.0 or v1.2.0 (semver; v prefix is stripped).
 *   - Mark a security release by putting [security] anywhere in the release notes.
 *   - Draft releases are ignored; GitHub "pre-release" ones are skipped too.
 */

$token = getenv('GH_TOKEN') ?: exit("GH_TOKEN is not set\n");
$projects = json_decode(file_get_contents(__DIR__ . '/projects.json'), true, 512, JSON_THROW_ON_ERROR);
$core = '8.x';

function esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function parse_version(string $v): ?array {
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(.+))?$/', $v, $m)) {
        return null;
    }
    return ['major' => $m[1], 'minor' => $m[2], 'patch' => $m[3], 'extra' => $m[4] ?? ''];
}

function gh_releases(string $repo, string $token): array {
    $ch = curl_init("https://api.github.com/repos/$repo/releases?per_page=100");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Accept: application/vnd.github+json',
            'User-Agent: release-history-generator',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200) {
        fwrite(STDERR, "GitHub API returned $code for $repo\n");
        exit(1);
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

foreach ($projects as $name => $p) {
    $link = 'https://github.com/' . $p['repo'];
    $releases = [];

    foreach (gh_releases($p['repo'], $token) as $r) {
        if ($r['draft'] || $r['prerelease']) {
            continue;
        }
        $version = ltrim($r['tag_name'], 'vV');
        if (!parse_version($version)) {
            continue;
        }
        $releases[] = [
            'version'  => $version,
            'tag'      => $r['tag_name'],
            'date'     => strtotime($r['published_at']),
            'security' => stripos($r['body'] ?? '', '[security]') !== false,
        ];
    }

    if (!$releases) {
        fwrite(STDERR, "No usable releases for $name, skipping\n");
        continue;
    }

    usort($releases, fn($a, $b) => version_compare($b['version'], $a['version']));
    $stable = array_values(array_filter($releases, fn($r) => parse_version($r['version'])['extra'] === ''));
    $recommended = parse_version(($stable[0] ?? $releases[0])['version'])['major'];
    $majors = array_values(array_unique(array_map(fn($r) => parse_version($r['version'])['major'], $releases)));

    $x  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $x .= "<project xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n";
    $x .= '  <title>' . esc($p['title']) . "</title>\n";
    $x .= '  <short_name>' . esc($name) . "</short_name>\n";
    $x .= '  <dc:creator>' . esc($p['creator'] ?? '') . "</dc:creator>\n";
    $x .= '  <type>' . esc($p['type'] ?? 'project_module') . "</type>\n";
    $x .= '  <api_version>' . $core . "</api_version>\n";
    $x .= "  <recommended_major>$recommended</recommended_major>\n";
    $x .= '  <supported_majors>' . implode(',', $majors) . "</supported_majors>\n";
    $x .= "  <default_major>$recommended</default_major>\n";
    $x .= "  <project_status>published</project_status>\n";
    $x .= '  <link>' . esc($link) . "</link>\n  <releases>\n";

    foreach ($releases as $r) {
        $v = parse_version($r['version']);
        $x .= "    <release>\n";
        $x .= '      <name>' . esc("$name {$r['version']}") . "</name>\n";
        $x .= '      <version>' . esc($r['version']) . "</version>\n";
        $x .= '      <tag>' . esc($r['tag']) . "</tag>\n";
        $x .= "      <version_major>{$v['major']}</version_major>\n";
        $x .= "      <version_minor>{$v['minor']}</version_minor>\n";
        $x .= "      <version_patch>{$v['patch']}</version_patch>\n";
        if ($v['extra'] !== '') {
            $x .= '      <version_extra>' . esc($v['extra']) . "</version_extra>\n";
        }
        $x .= "      <status>published</status>\n";
        $x .= '      <release_link>' . esc("$link/releases/tag/{$r['tag']}") . "</release_link>\n";
        $x .= '      <download_link>' . esc("$link/archive/refs/tags/{$r['tag']}.zip") . "</download_link>\n";
        $x .= "      <date>{$r['date']}</date>\n";
        if ($r['security']) {
            $x .= "      <terms><term><name>Release type</name><value>Security update</value></term></terms>\n";
        }
        $x .= "    </release>\n";
    }
    $x .= "  </releases>\n</project>\n";

    $dir = __DIR__ . "/public/release-history/$name";
    is_dir($dir) || mkdir($dir, 0777, true);
    file_put_contents("$dir/$core", $x);
    echo "Wrote $name (" . count($releases) . " releases)\n";
}

file_put_contents(__DIR__ . '/public/.nojekyll', '');
