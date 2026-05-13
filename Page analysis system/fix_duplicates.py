"""
Script to remove duplicate/orphaned card-building code from report-connect.js
This removes the old simple-card builders for strengths.html and weaknesses.html
that conflict with the deep-card builders lower in the file.
"""
import re

fname = r'js\report-connect.js'

with open(fname, encoding='utf-8') as f:
    content = f.read()

# Find the marker we left and the next major section
# We need to remove everything between the marker comment and the detailed-analysis block
start_marker = '      // [Orphaned duplicate card builders removed'
end_marker = '      // ==========================================\n      // PAGE: detailed-analysis.html'

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx == -1:
    # marker not found, try alternate
    start_marker = '      // PAGE: strengths.html — Profile Info only'
    start_idx = content.find(start_marker)
    # find end of the weaknesses profile-info block
    wk_end = content.find('      }\n\n\n\n      // ==========================================\n      // PAGE: detailed-analysis.html')
    if wk_end == -1:
        wk_end = content.find('\n      // ==========================================\n      // PAGE: detailed-analysis.html')

if start_idx != -1 and end_idx != -1 and start_idx < end_idx:
    # Keep everything before the junk, skip to detailed-analysis section
    new_content = content[:start_idx] + '\n' + content[end_idx:]
    with open(fname, 'w', encoding='utf-8') as f:
        f.write(new_content)
    print('SUCCESS: Removed orphaned duplicate code block')
    print(f'File reduced from {len(content)} to {len(new_content)} bytes')
else:
    # Fallback: count lines and remove by line number
    lines = content.splitlines(keepends=True)
    print(f'Total lines: {len(lines)}')
    
    # Find the orphaned start (look for standalone iconMap line not inside a function)
    orphan_start = None
    orphan_end = None
    
    for i, line in enumerate(lines):
        if "const iconMap = ['🎨','🖼️','💬','📅','⏱️','💡','🔥','🚀'];" in line:
            # Check if this is the orphaned one (not inside the second block)
            context_before = ''.join(lines[max(0,i-5):i])
            if 'strengths.forEach' not in context_before and 'weaknesses.forEach' not in context_before:
                orphan_start = i
                print(f'Found orphan start at line {i+1}: {line[:60]}')
                break
    
    for i, line in enumerate(lines):
        if '// PAGE: detailed-analysis.html (ULTIMATE AUDIT)' in line:
            orphan_end = i
            print(f'Found detailed-analysis section at line {i+1}')
            break
    
    if orphan_start and orphan_end and orphan_start < orphan_end:
        new_lines = lines[:orphan_start] + lines[orphan_end:]
        with open(fname, 'w', encoding='utf-8') as f:
            f.writelines(new_lines)
        print(f'SUCCESS: Removed lines {orphan_start+1} to {orphan_end}')
        print(f'File reduced from {len(lines)} to {len(new_lines)} lines')
    else:
        print(f'FAILED: Could not identify orphan block. start={orphan_start}, end={orphan_end}')
        print('Please run manually.')
