import os
import glob

files = glob.glob(r'c:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system\*.html')

for filepath in files:
    if os.path.basename(filepath) in ['checkout.html', 'packages.html', 'analyzing.html']:
        continue
        
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Add packages to sidebar
    if 'packages.html' not in content:
        content = content.replace('      <a href="plan.html" class="nav-item"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg> الخطة المقترحة</a>\n    </nav>', '      <a href="plan.html" class="nav-item"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg> الخطة المقترحة</a>\n      <a href="packages.html" class="nav-item"><svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg> الباقات والأسعار</a>\n    </nav>')
        
        content = content.replace('      <a href="plan.html" class="nav-item active"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg> الخطة المقترحة</a>\n    </nav>', '      <a href="plan.html" class="nav-item active"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg> الخطة المقترحة</a>\n      <a href="packages.html" class="nav-item"><svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg> الباقات والأسعار</a>\n    </nav>')

    # Update all checkout.html links to packages.html
    content = content.replace('href="checkout.html"', 'href="packages.html"')
    # Update button text slightly if necessary
    content = content.replace('إتمام التعاقد', 'الاطلاع على الباقات')
    content = content.replace('ترقية حسابي الآن', 'عرض باقات النمو')
    
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)
