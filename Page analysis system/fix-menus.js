const fs = require('fs');
const path = require('path');

const menuTemplate = `    <nav class="nav-menu">
      <a href="report.html" class="nav-item {{report.html}}"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg> النتائج العامة</a>
      <a href="strengths.html" class="nav-item {{strengths.html}}"><svg viewBox="0 0 24 24"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg> نقاط القوة</a>
      <a href="weaknesses.html" class="nav-item {{weaknesses.html}}"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg> نقاط الضعف</a>
      <a href="journey.html" class="nav-item {{journey.html}}"><svg viewBox="0 0 24 24"><path d="M3 3h18v2H3V3zm0 4h18v2H3V7zm0 4h12v2H3v-2zm0 4h12v2H3v-2zm0 4h8v2H3v-2z"/></svg> رحلة العميل</a>
      <a href="content.html" class="nav-item {{content.html}}"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg> المحتوى</a>
      <a href="competitors.html" class="nav-item {{competitors.html}}"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg> المنافسون</a>
      <a href="ads.html" class="nav-item {{ads.html}}"><svg viewBox="0 0 24 24"><path d="M11 15h2v2h-2zm0-8h2v6h-2zm.99-5C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/></svg> الإعلانات</a>
      <a href="recommendations.html" class="nav-item {{recommendations.html}}"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg> التوصيات</a>
      <a href="plan.html" class="nav-item {{plan.html}}"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg> الخطة المقترحة</a>
      <a href="packages.html" class="nav-item {{packages.html}}"><svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg> الباقات والأسعار</a>
    </nav>`;

const directoryPath = __dirname;

fs.readdir(directoryPath, (err, files) => {
  if (err) {
    return console.log('Unable to scan directory: ' + err);
  }

  files.forEach((file) => {
    if (path.extname(file) === '.html') {
      const filePath = path.join(directoryPath, file);
      let content = fs.readFileSync(filePath, 'utf8');

      // Check if it has a nav menu
      if (content.includes('<nav class="nav-menu">')) {
        let replacement = menuTemplate.replace(/\{\{([a-zA-Z0-9.-]+)\}\}/g, (match, p1) => {
          if (p1 === file) {
            return 'active';
          }
          return '';
        });

        // Some nav items might end up with "nav-item " so we trim it
        replacement = replacement.replace(/class="nav-item "/g, 'class="nav-item"');

        // Regex to replace the entire nav block
        const regex = /<nav class="nav-menu">[\s\S]*?<\/nav>/;
        content = content.replace(regex, replacement);

        fs.writeFileSync(filePath, content, 'utf8');
        console.log(\`Updated menu in \${file}\`);
      }
    }
  });
});
