import PyPDF2
import sys

def search_in_pdf(filepath, out_file):
    out_file.write(f"Searching in: {filepath}\n")
    reader = PyPDF2.PdfReader(filepath)
    text = ""
    for page in reader.pages:
        text += page.extract_text()
    
    lines = text.split('\n')
    found = False
    for i, line in enumerate(lines):
        if 'تحكم' in line or 'أدمن' in line or 'Admin' in line or 'dashboard' in line.lower() or 'إدارة' in line:
            out_file.write(f"--- Found on line {i} ---\n")
            start = max(0, i-5)
            end = min(len(lines), i+80)
            for j in range(start, end):
                out_file.write(f"{j}: {lines[j]}\n")
            out_file.write('='*50 + '\n')
            found = True
            break
    if not found:
        out_file.write("No matches found.\n")

f1 = r"c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\مختبر النمو - محتوى الصفحات.docx.pdf"
f2 = r"c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\مختبر النمو.docx.pdf"

with open("admin_output.txt", "w", encoding="utf-8") as out:
    search_in_pdf(f1, out)
    out.write("\n" + "#"*50 + "\n")
    search_in_pdf(f2, out)
