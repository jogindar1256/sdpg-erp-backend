#!/usr/bin/env python3
"""
Pre-renders the Hindi (Devanagari) text of the university exam forms
(P-4 / P-6 / P-7 / P-9) to PNG, using proper OpenType shaping (libraqm).

Why: the PDF engine (dompdf) cannot shape Devanagari — matras and conjuncts
come out broken. These labels are static, so they are drawn here once and
embedded as images. Re-run only when a Hindi string changes:

    python3 tools/make_hindi_assets.py      (needs Pillow built with raqm)

Output: resources/pdf-assets/hindi/<key>.png + manifest.json (css px size).
"""
import json, os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'resources/pdf-assets/hindi')
REG = os.path.join(ROOT, 'storage/fonts/NotoSansDevanagari-Regular.ttf')
BOLD = os.path.join(ROOT, 'storage/fonts/NotoSansDevanagari-Bold.ttf')
SCALE = 4  # render at 4x, show at 1x -> crisp when printed

# key: (text, css font px, bold, rotate_deg, wrap_css_px or None)
A = {
    # University letter-head title: regular weight with a light stroke (matches the printed forms)
    'title':        ('दीन दयाल उपाध्याय गोरखपुर विश्वविद्यालय, गोरखपुर', 30, False, 0, None),
    # ── P-4
    'p4_sub1':      ('कम्प्यूटर प्रयोग हेतु अनुपस्थित/अनुचित साधन विवरण', 14, True, 0, None),
    'p4_sub2':      ('इस प्रपत्र को भरकर परीक्षा नियंत्रक को अवश्य भेजें', 12, True, 0, None),
    'p4_nirdesh':   ('निर्देश :', 12, True, 0, None),
    'p4_i1':        ('1.   यदि परीक्षा केन्द्र पर अन्य महाविद्यालय संलग्न है, तो उनके लिए पृथक प्रपत्र (P-4) पूरित किया जाय।', 11, False, 0, None),
    'p4_i2':        ('2.   प्रत्येक प्रश्न-पत्र संकेतांक हेतु पृथक प्रपत्र (P-4) पूरित किया जाय।', 11, False, 0, None),
    'p4_i3':        ('3.   अनुक्रमांक बढ़ते क्रम में ऊपर से नीचे स्तंभ में ही लिखे जायें।', 11, False, 0, None),
    'p4_i4':        ('4.   इस प्रपत्र की प्रतियाँ उ0पु0 पैकेटों के साथ ही विश्वविद्यालय को हस्तांतरित की जाय।', 11, False, 0, None),
    'l_centre':     ('परीक्षा केन्द्र', 11, False, 0, None),
    'l_college':    ('महाविद्यालय', 11, False, 0, None),
    'l_exam':       ('परीक्षा', 11, False, 0, None),
    'l_part':       ('भाग', 11, False, 0, None),
    'l_subject':    ('विषय', 11, False, 0, None),
    'l_paper':      ('प्रश्न–पत्र', 11, False, 0, None),
    'l_code':       ('संकेतांक', 11, False, 0, None),
    'l_examdate':   ('परीक्षा दिनांक', 11, False, 0, None),
    'l_session':    ('सत्र', 11, False, 0, None),
    'l_present':    ('उपस्थित परीक्षार्थियों का संख्या', 11, False, 0, None),
    'p4_h_abs':     ('अनुपस्थित परीक्षार्थी', 13, False, 0, None),
    'p4_h_ufm':     ('अनुचित साधन प्रयोग में पकड़े गये परीक्षार्थी', 12, False, 0, 190),
    'h_sr':         ('क्रम सं0', 10, False, 0, None),
    'h_roll':       ('अनुक्रमांक', 11, False, 0, None),
    'h_total':      ('कुल संख्या:', 10, False, 0, None),
    # ── P-6
    'p6_para':      ('केन्द्र व्यवस्थापक द्वारा यह प्रपत्र तीन प्रतियों में पूरित किया जाय। पहली प्रति उत्तर पुस्तिकाओं के बन्डल में रखी जाय। दूसरी प्रति अलग से उ0पु0 के बन्डल के साथ परीक्षा नियंत्रक/समन्वयक वि0वि0 परीक्षा को हस्तान्तरित किया जाय। इस प्रपत्र की तीसरी प्रति केन्द्राध्यक्ष अपने पास सुरक्षित रखें। पहली प्रति जो उ0 पु0 पैकेट में रखी जाय।', 11, False, 0, 690),
    'p6_college':   ('महाविद्यालय का नाम -', 11, False, 0, None),
    'p6_centre':    ('परीक्षा केन्द्र का नाम -', 11, False, 0, None),
    'p6_class':     ('कक्षा :', 11, False, 0, None),
    'p6_year':      ('परीक्षा', 11, False, 0, None),
    'p6_1':         ('विषय', 11, False, 0, None),
    'p6_1b':        ('प्रश्न पत्र', 11, False, 0, None),
    'p6_1c':        ('संकेतांक', 11, False, 0, None),
    'p6_2':         ('पंजीकृत अभ्यर्थियों की संख्या', 11, False, 0, None),
    'p6_3':         ('परीक्षा में सम्मिलित हुए अभ्यर्थियों की संख्या', 11, False, 0, None),
    'p6_4':         ('अनुचित साधन प्रयोग करते हुए पकड़े गये अभ्यर्थियों की संख्या', 11, False, 0, None),
    'p6_5':         ('अनुपस्थित अभ्यर्थियों की संख्या', 11, False, 0, None),
    'p6_roll':      ('अनुक्रमांक', 11, False, 0, None),
    'p6_6':         ('बैक पेपर / अंक सुधार अभ्यर्थियों के अनुक्रमांक जो परीक्षा में सम्मिलित हुए हैं', 11, False, 0, None),
    'p6_7':         ('पार्सल में रखी गयी उत्तर पुस्तिकाओं की वास्तविक संख्या', 11, False, 0, None),
    # ── P-9
    'p9_sub1':      ('(उत्तर पुस्तिकाओं के पैकेटों का हस्तान्तरण प्रपत्र)', 13, True, 0, None),
    'p9_sub2':      ('(केन्द्राध्यक्ष द्वारा कार्बन लगाकर तीन प्रतियों मे तैयार किया जाय)', 11, True, 0, None),
    'p9_centre':    ('परीक्षा  केन्द्र', 11, False, 0, None),
    'p9_centrecode':('केन्द्र संख्या', 11, False, 0, None),
    'p9_joint':     ('संयुक्त महाविद्यालय के नाम (यदि कोई हो)', 11, False, 0, None),
    'p9_note':      ('नोट : केन्द्र पर बनाये गये प्रथम से अन्तिम तक यही पैकेट क्रमांक लगातार डाले जायें। पैकेट पर यह क्रमांक केन्द्र संख्या के नीचे डाला जाये।', 10, False, 0, 690),
    'p9_detail':    ('विवरण प्रारूप', 12, True, 0, None),
    'p9_ufmhead':   ('अनुचित साधन प्रयोग से सम्बन्धित पैकेटों का विवरण', 12, True, 0, None),
    'p9_recv1':     ('उपर्युक्त पैकेट क्रमांक ................... से .................... तक के सीलबन्द पैकेट एवं तत्सम्बन्धित प्रपत्र प्राप्त किया।', 10, False, 0, 330),
    'p9_sign_recv': ('हस्ताक्षर प्राप्तकर्ता दिनांक/समय', 10, False, 0, None),
    'p9_two_copies':('(इस प्रपत्र की दो प्रतियाँ प्राप्तकर्ता को दी जाय)', 10, False, 0, None),
    'p9_sign_cs':   ('हस्ताक्षर केन्द्राध्यक्ष दिनांक/समय', 10, False, 0, None),
    'p9_recv2':     ('उपर्युक्त पैकेट के सीलबन्द पैकेट एवं तत्सम्बन्धित पूरित प्रपत्र P.4, P.6 एवं P.7 प्राप्त किया।', 10, False, 0, None),
    'p9_coord':     ('कृते परीक्षा समन्वयक, तिथि/समय', 10, False, 0, None),
    # P-9 rotated column heads (90° counter-clockwise, read bottom-to-top)
    'p9c_1':  ('क्रम सं0', 10, False, 90, 118),
    'p9c_2':  ('परीक्षा तिथि', 10, False, 90, 118),
    'p9c_3':  ('दिन', 10, False, 90, 118),
    'p9c_4':  ('सत्र', 10, False, 90, 118),
    'p9c_5':  ('परीक्षा/प्रश्न-पत्र', 10, False, 90, 118),
    'p9c_6':  ('विषय/प्रश्न-पत्र', 10, False, 90, 118),
    'p9c_7':  ('प्रश्न-पत्र/संकेतांक', 10, False, 90, 118),
    'p9c_8':  ('उपस्थित परीक्षार्थियों की संख्या', 10, False, 90, 118),
    'p9c_9':  ('कितने पैकेट बनाये गये', 10, False, 90, 118),
    'p9c_10': ('पैकेट में उ0 पु0 की संख्या', 10, False, 90, 118),
    'p9c_11': ('पैकेट क्रमांक', 10, False, 90, 118),
    'p9c_12': ('विश्वविद्यालय के प्रयोग के लिये', 10, False, 90, 118),
    # ── P-1
    'p1_centre':    ('परीक्षा केन्द्र:–', 11, True, 0, None),
    'p1_room':      ('कक्ष सं०:–', 11, True, 0, None),
    'p1_group':     ('परीक्षा/ग्रुप:–', 11, True, 0, None),
    'p1_time':      ('समय (सत्र)', 11, True, 0, None),
    'p1_date':      ('दिनांक:–', 11, True, 0, None),
    'p1_h1':        ('प्रतिदिन की उत्तर पुस्तिकाओं के प्रयोग का विवरण', 15, True, 0, None),
    'p1_exam':      ('परीक्षा', 11, False, 0, None),
    'p1_subcode':   ('विषय – कोड़', 11, False, 0, None),
    'p1_omr':       ('उत्तर पुस्तिका/ओ०एम०आर०शीट', 10, False, 0, None),
    'p1_filled':    ('पूरित', 11, False, 0, None),
    'p1_used':      ('प्रयुक्त', 11, False, 0, None),
    'p1_ret':       ('वापस', 11, False, 0, None),
    'p1_h2':        ('अनुपस्थित अभ्यर्थियों का विवरण', 15, True, 0, None),
    'p1_roll':      ('अभ्यर्थियों का अनुक्रमांक', 11, False, 0, None),
    'p1_sum':       ('योग', 11, False, 0, None),
    'p1_other':     ('अन्य विवरण:–', 11, True, 0, None),
    'p1_sign':      ('परिप्रेक्षकों के हस्ताक्षर:–', 11, True, 0, None),
    'p1_cs':        ('केन्द्राध्यक्ष के हस्ताक्षर', 11, True, 0, None),
    # ── P-7
    'p7_ins':       ('निर्देश–परीक्षा भवन में परीक्षा प्रारम्भ होने के एक घण्टे के अन्दर प्रत्येक उपस्थित छात्र से अपने अनुक्रमांक के अनुसार इस प्रपत्र पर हस्ताक्षर कराये जायें। प्रतिदिन के समस्त P-7 प्रपत्र पर पृष्ठ संख्या डालकर P-6 के साथ परीक्षा नियंत्रक के नाम विशेष वाहक द्वारा भेजे जायें। इसकी छाया प्रति अपने संदर्भ के लिए सुरक्षित रखी जा सकती है।', 10, True, 0, 700),
    'p7_1': ('1.परीक्षा केन्द्र का नाम:-', 11, True, 0, None),
    'p7_2': ('2.केन्द्र संख्या:-', 11, True, 0, None),
    'p7_3': ('3.कक्ष संख्या:-', 11, True, 0, None),
    'p7_4': ('4.परीक्षा तिथि:-', 11, True, 0, None),
    'p7_5': ('5.सत्र:-', 11, True, 0, None),
    'p7_6': ('6.पेपर संकेतांक:-', 11, True, 0, None),
    'p7_7': ('7.परीक्षा:-', 11, True, 0, None),
    'p7_8': ('8.भाग:-', 11, True, 0, None),
    'p7_9': ('9.विषय एवं प्रश्न पत्र:-', 11, True, 0, None),
}

os.makedirs(OUT, exist_ok=True)
manifest = {}
for key, (text, px, bold, rot, wrap) in A.items():
    font = ImageFont.truetype(BOLD if bold else REG, px * SCALE, layout_engine=ImageFont.Layout.RAQM)
    # greedy word wrap on rendered width
    lines = [text]
    if wrap:
        words, lines, cur = text.split(' '), [], ''
        for w in words:
            t = (cur + ' ' + w).strip()
            if font.getlength(t) <= wrap * SCALE or not cur:
                cur = t
            else:
                lines.append(cur); cur = w
        lines.append(cur)
    lh = int(px * SCALE * (1.4 if rot else 1.75))
    W = int(max(font.getlength(l) for l in lines)) + 8 * SCALE
    H = lh * len(lines) + 4 * SCALE
    img = Image.new('RGBA', (W, H), (255, 255, 255, 0))
    d = ImageDraw.Draw(img)
    for i, l in enumerate(lines):
        d.text((4 * SCALE, i * lh + int(px * SCALE * 0.25)), l, font=font, fill=(0, 0, 0, 255),
               stroke_width=(SCALE if key == 'title' else 0), stroke_fill=(0, 0, 0, 255))
    bbox = img.getbbox()
    if bbox:
        pad = 2 * SCALE
        img = img.crop((max(0, bbox[0] - pad), max(0, bbox[1] - pad), min(W, bbox[2] + pad), min(H, bbox[3] + pad)))
    if rot:
        img = img.rotate(rot, expand=True)
    img.save(os.path.join(OUT, key + '.png'), optimize=True)
    manifest[key] = [round(img.width / SCALE, 1), round(img.height / SCALE, 1)]
json.dump(manifest, open(os.path.join(OUT, 'manifest.json'), 'w'), indent=0)
print(len(manifest), 'assets')
