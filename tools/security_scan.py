import os
import re

base_dir = r"Project-SokoHub"

sql_patterns = [
    re.compile(r'\$pdo->query\([^)]*\$'),
    re.compile(r'\$pdo->exec\([^)]*\$'),
    re.compile(r'SELECT\s+.*?\s+FROM.*?\$', re.IGNORECASE),
    re.compile(r'INSERT\s+INTO.*?\$', re.IGNORECASE),
    re.compile(r'UPDATE\s+.*?SET.*?\$', re.IGNORECASE),
    re.compile(r'DELETE\s+FROM.*?\$', re.IGNORECASE),
]

cmd_pattern = re.compile(r'\b(exec|system|passthru|shell_exec|popen|proc_open)\s*\(')
include_pattern = re.compile(r'\b(include|require)(_once)?\s*\(?.*\$_(GET|POST|REQUEST|COOKIE)')
csrf_check_pattern = re.compile(r'\$_SERVER\[[\'"]REQUEST_METHOD[\'"]\]\s*===?\s*[\'"]POST[\'"]')
csrf_verify_pattern = re.compile(r'csrf_token|validate_csrf|verify_csrf')

results = {
    'sql': [],
    'cmd': [],
    'include': [],
    'missing_csrf': []
}

for root, dirs, files in os.walk(base_dir):
    if '.git' in root or 'vendor' in root or 'node_modules' in root:
        continue
    for f in files:
        if not f.endswith('.php'):
            continue
        filepath = os.path.join(root, f)
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as fp:
            content = fp.read()
            lines = content.splitlines()

            # Check if file processes POST requests
            has_post = any('$_POST' in line or csrf_check_pattern.search(line) for line in lines)
            has_csrf = bool(csrf_verify_pattern.search(content))
            if has_post and not has_csrf and 'api' not in filepath and 'callback' not in filepath and 'tests' not in filepath:
                results['missing_csrf'].append((filepath, 0, "Processes POST but no csrf check found"))

            for idx, line in enumerate(lines, 1):
                # Ignore comments
                sline = line.strip()
                if sline.startswith('//') or sline.startswith('#') or sline.startswith('*'):
                    continue

                for p in sql_patterns:
                    if p.search(line):
                        results['sql'].append((filepath, idx, sline))
                        break

                if cmd_pattern.search(line):
                    results['cmd'].append((filepath, idx, sline))

                if include_pattern.search(line):
                    results['include'].append((filepath, idx, sline))

print("=== POTENTIAL SQL CONCATENATION ===")
for path, idx, line in results['sql']:
    print(f"{path}:{idx} -> {line[:120]}")

print("\n=== COMMAND EXECUTION ===")
for path, idx, line in results['cmd']:
    print(f"{path}:{idx} -> {line[:120]}")

print("\n=== DYNAMIC INCLUDES ===")
for path, idx, line in results['include']:
    print(f"{path}:{idx} -> {line[:120]}")

print("\n=== FILES WITH POST BUT POTENTIALLY NO CSRF CHECK ===")
for path, idx, line in results['missing_csrf']:
    print(f"{path} -> {line}")
