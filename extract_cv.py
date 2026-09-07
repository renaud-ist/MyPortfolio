import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

base = Path(r'c:/Users/TRIS/Desktop/MyProjects/MyPortFolio')
path = base / 'resume' / 'CV.docx'
out_path = base / 'cv_text.txt'

with zipfile.ZipFile(path) as z:
    xml_data = z.read('word/document.xml')

root = ET.fromstring(xml_data)
ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
out = []
for p in root.findall('.//w:p', ns):
    text = ''.join((t.text or '') for t in p.findall('.//w:t', ns)).strip()
    if text:
        out.append(text)

out_path.write_text('\n'.join(out), encoding='utf-8')
print('\n'.join(out[:250]))
