import PyPDF2

def extract():
    try:
        reader = PyPDF2.PdfReader(r'c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\مختبر النمو - محتوى الصفحات.docx.pdf')
        text1 = ''.join([page.extract_text() for page in reader.pages])
    except Exception as e:
        text1 = str(e)

    try:
        reader2 = PyPDF2.PdfReader(r'c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\مختبر النمو.docx.pdf')
        text2 = ''.join([page.extract_text() for page in reader2.pages])
    except Exception as e:
        text2 = str(e)

    with open(r'c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\extracted_text.txt', 'w', encoding='utf-8') as f:
        f.write('=== مختبر النمو - محتوى الصفحات ===\n')
        f.write(text1)
        f.write('\n\n=== مختبر النمو ===\n')
        f.write(text2)

extract()
