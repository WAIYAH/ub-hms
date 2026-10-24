"""Parts shared by the three chapter documents: cover page, glossary and reference list."""
import os

HERE = os.path.dirname(os.path.abspath(__file__))
TEMPLATE = os.path.join(HERE, 'template.docx')
FIG = os.path.join(HERE, '..', 'figures')
SHOT = os.path.join(HERE, '..', 'screenshots')
OUTDIR = os.path.join(HERE, '..', 'chapters')
COPYDIR = r'D:\SHELAX\SAMPLE Documents\OUTPUT'

STUDENT = 'SUSAN WANJIKU WAWERU'
REG_NO = '25/04035'
SUPERVISOR = 'DAVID OLOO OUMA'
DATE = 'DECEMBER 2026'


def fig(name):
    return os.path.join(FIG, name + '.png')


def shot(name):
    return os.path.join(SHOT, name + '.png')


def cover_lines(document_title_lines):
    """Cover page in the style of the approved proposal."""
    lines = [
        ('DIPLOMA IN INFORMATION TECHNOLOGY', 'bold'),
        ('UNIT: DIT-503', 'bold'),
        ('PROGRAMMING PROJECT', 'bold'),
        ('', 'gap'),
        ('PROJECT TITLE:', 'bold'),
        ('UHAI BORA HOSPITAL MANAGEMENT SYSTEM', 'big'),
        ('A Web-Based Hospital Management System for Uhai Bora Hospital, Nairobi, Kenya', 'line'),
        ('', 'gap'),
    ]
    for t in document_title_lines:
        lines.append((t, 'big' if t.isupper() else 'bold'))
    lines += [
        ('', 'gap'),
        ('BY:', 'bold'),
        (STUDENT, 'bold'),
        (f'REGISTRATION NO – {REG_NO}', 'bold'),
        ('', 'gap'),
        ('SUPERVISOR:', 'bold'),
        (SUPERVISOR, 'bold'),
        ('', 'gap'),
        (DATE, 'bold'),
        ('', 'gap'),
        ('This document is submitted in partial fulfilment of the requirements of the award of a Diploma in Information Technology in KCA University.', 'line'),
    ]
    return lines


GLOSSARY = [
    ('Agile', 'A way of building software in short cycles, each ending with a working part that users review.'),
    ('Allergy alert', 'A reminder shown to staff when a patient has a recorded allergy.'),
    ('Apache', 'The web server program that sends UB-HMS pages to browsers. It is part of XAMPP.'),
    ('Appointment slot', 'A 20-minute period in a doctor\'s day that can be booked for one patient.'),
    ('Audit trail', 'The record of who did what, to which record, from which computer and when.'),
    ('Batch', 'A quantity of one medicine that came in one delivery and has one expiry date.'),
    ('bcrypt', 'A method of turning a password into a hash that is slow to guess; used to store passwords safely.'),
    ('Browser', 'The program staff use to open UB-HMS, such as Chrome, Edge or Firefox.'),
    ('Check-in', 'Opening a visit when a patient arrives at the hospital.'),
    ('Content Security Policy (CSP)', 'A rule sent with every page that tells the browser which scripts and styles it may run, which blocks injected scripts.'),
    ('CSRF (cross-site request forgery)', 'An attack in which another website tricks a signed-in user\'s browser into sending a request. UB-HMS blocks it with a secret token on every form.'),
    ('CSV', 'Comma-separated values: a plain file of rows and columns that a spreadsheet can open.'),
    ('Data dictionary', 'A list of every field in the database with its type, size and allowed values.'),
    ('DFD', 'Data flow diagram: a picture of how information moves between people, processes and stores.'),
    ('Dispense', 'To give medicines to a patient against a prescription.'),
    ('ERD', 'Entity relationship diagram: a picture of the records in a database and how they are linked.'),
    ('Expiry date', 'The date after which a medicine must not be given.'),
    ('Foreign key (FK)', 'A field that holds the number of a record in another table, so that the two are linked.'),
    ('Hash', 'A fixed-length code made from a password. It cannot be turned back into the password.'),
    ('HTTP / HTTPS', 'The rules a browser and a web server use to talk. HTTPS adds encryption so that nobody on the network can read the traffic.'),
    ('ICD-10', 'The International Classification of Diseases, 10th revision: a list of codes for diagnoses published by the World Health Organization.'),
    ('Index', 'A structure in the database that lets it find rows quickly without reading the whole table.'),
    ('InnoDB', 'The storage engine of MariaDB that supports transactions and foreign keys.'),
    ('Invoice', 'The bill for one visit, listing every service charged.'),
    ('KSh', 'Kenya shillings.'),
    ('LAN', 'Local area network: the wired network inside the hospital.'),
    ('Ledger (stock ledger)', 'The list of every change to stock: what came in, what was given out and what was adjusted.'),
    ('MariaDB', 'The database program in XAMPP that stores all UB-HMS data. It works like MySQL.'),
    ('M-Pesa', 'A mobile money service. A payment is proved by a 10-character confirmation code.'),
    ('Normalisation', 'Arranging data into tables so that each fact is stored once.'),
    ('OP number', 'Outpatient number: the unique number of a patient, written OPyy-nnnnn, for example OP26-00042.'),
    ('OWASP Top 10', 'A list of the ten most serious kinds of weakness in web applications, published by the Open Worldwide Application Security Project.'),
    ('PDO', 'PHP Data Objects: the PHP way of talking to a database with prepared statements.'),
    ('Permission', 'The right to open one page or do one action. Each role holds a set of permissions.'),
    ('PHP', 'The programming language that runs on the server and builds the UB-HMS pages.'),
    ('Prepared statement', 'A database query in which the typed values travel separately from the SQL text, so typed text can never change the query.'),
    ('Primary key (PK)', 'The field that identifies one row of a table.'),
    ('Prescription', 'The doctor\'s instruction to the pharmacy: which medicines, how much and how to take them.'),
    ('Reorder level', 'The stock level at or below which a medicine is shown as Low and should be ordered.'),
    ('Role', 'A set of permissions given to a staff member according to their work, for example Nurse or Cashier.'),
    ('Scrum', 'A framework of Agile in which work is done in sprints of fixed length.'),
    ('Session', 'The period between a person signing in and signing out, tracked by a cookie in the browser.'),
    ('SQL injection', 'An attack in which typed text is made to run as database commands. Prepared statements prevent it.'),
    ('SRS / SDS', 'System requirements specification (Chapter 4) and system design specification (Chapter 5).'),
    ('Sprint', 'A two-week cycle of Agile in which a planned part of the system is designed, built and tested.'),
    ('Synthetic data', 'Made-up data that looks like real data but belongs to nobody. All data in this project is synthetic.'),
    ('Transaction', 'A group of database changes that are saved together or not at all.'),
    ('Triage', 'The first check by a nurse, when vital signs are measured, before the patient sees a doctor.'),
    ('UAT', 'User acceptance testing: staff use the finished system to confirm that it meets their needs.'),
    ('UC / US / REQ', 'Use case, user story and requirement. Each has a number, such as UC-09, US-07 and REQ-013.'),
    ('UPS', 'Uninterruptible power supply: a battery that keeps the server running during a power cut.'),
    ('Use case', 'A description of one goal that a user achieves with the system, step by step.'),
    ('Visit', 'One attendance of one patient at the hospital, from check-in to closing; the invoice belongs to the visit.'),
    ('Vital signs', 'Temperature, pulse, blood pressure, breathing rate, oxygen saturation, weight and height.'),
    ('Walk-in', 'A patient who arrives without an appointment.'),
    ('XAMPP', 'A free package that installs Apache, MariaDB and PHP together on one computer.'),
    ('XSS (cross-site scripting)', 'An attack in which a script typed into a field runs in another person\'s browser. UB-HMS prevents it by escaping all text it shows.'),
]


REFERENCES = {
    'beck': 'Beck, K., Beedle, M., van Bennekum, A., Cockburn, A., Cunningham, W., Fowler, M., Grenning, J., Highsmith, J., Hunt, A., Jeffries, R., Kern, J., Marick, B., Martin, R. C., Mellor, S., Schwaber, K., Sutherland, J., & Thomas, D. (2001). *Manifesto for agile software development*. Agile Alliance. https://agilemanifesto.org',
    'chen': 'Chen, P. P.-S. (1976). The entity-relationship model: Toward a unified view of data. *ACM Transactions on Database Systems, 1*(1), 9–36. https://doi.org/10.1145/320434.320440',
    'codd': 'Codd, E. F. (1970). A relational model of data for large shared data banks. *Communications of the ACM, 13*(6), 377–387. https://doi.org/10.1145/362384.362685',
    'dennis': 'Dennis, A., Wixom, B. H., & Tegarden, D. (2015). *Systems analysis and design: An object-oriented approach with UML* (5th ed.). John Wiley & Sons.',
    'elmasri': 'Elmasri, R., & Navathe, S. B. (2016). *Fundamentals of database systems* (7th ed.). Pearson Education.',
    'ieee830': 'IEEE. (1998). *IEEE recommended practice for software requirements specifications* (IEEE Std 830-1998). Institute of Electrical and Electronics Engineers.',
    'mariadb': 'MariaDB Foundation. (n.d.). *MariaDB server documentation*. Retrieved October 24, 2026, from https://mariadb.com/kb/en/documentation/',
    'nielsen': 'Nielsen, J. (1994). *10 usability heuristics for user interface design*. Nielsen Norman Group. https://www.nngroup.com/articles/ten-usability-heuristics/',
    'owasp': 'OWASP Foundation. (2021). *OWASP Top 10: 2021*. https://owasp.org/Top10/',
    'owaspcheat': 'OWASP Foundation. (n.d.). *Password storage cheat sheet*. OWASP Cheat Sheet Series. Retrieved October 24, 2026, from https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html',
    'php': 'The PHP Group. (n.d.). *PHP manual*. Retrieved October 24, 2026, from https://www.php.net/manual/en/',
    'provos': 'Provos, N., & Mazières, D. (1999). A future-adaptable password scheme. In *Proceedings of the 1999 USENIX Annual Technical Conference, FREENIX Track* (pp. 81–91). USENIX Association.',
    'kenya2010': 'Republic of Kenya. (2010). *The Constitution of Kenya, 2010*. National Council for Law Reporting.',
    'kenya2017': 'Republic of Kenya. (2017). *The Health Act (No. 21 of 2017)*. National Council for Law Reporting.',
    'kenya2019': 'Republic of Kenya. (2019). *The Data Protection Act (No. 24 of 2019)*. National Council for Law Reporting.',
    'kenya2023': 'Republic of Kenya. (2023). *The Digital Health Act (No. 15 of 2023)*. National Council for Law Reporting.',
    'schwaber': 'Schwaber, K., & Sutherland, J. (2020). *The Scrum guide: The definitive guide to Scrum: The rules of the game*. Scrum.org. https://scrumguides.org',
    'shneiderman': 'Shneiderman, B., Plaisant, C., Cohen, M., Jacobs, S., & Elmqvist, N. (2016). *Designing the user interface: Strategies for effective human–computer interaction* (6th ed.). Pearson Education.',
    'shaikh': 'Shaikh, M., Vayani, A. H., Akram, S., & Qamar, N. (2022). Open-source electronic health record systems: A systematic review of most recent advances. *Health Informatics Journal, 28*(2). https://doi.org/10.1177/14604582221099828',
    'sommerville': 'Sommerville, I. (2016). *Software engineering* (10th ed.). Pearson Education.',
    'w3c': 'World Wide Web Consortium. (2018). *Web content accessibility guidelines (WCAG) 2.1*. https://www.w3.org/TR/WCAG21/',
    'who2019': 'World Health Organization. (2019). *International statistical classification of diseases and related health problems* (10th revision, 2019 ed.). https://icd.who.int/browse10/2019/en',
    'who2021': 'World Health Organization. (2021). *Global strategy on digital health 2020–2025*. World Health Organization.',
    'wiegers': 'Wiegers, K. E., & Beatty, J. (2013). *Software requirements* (3rd ed.). Microsoft Press.',
    'muinga2020': 'Muinga, N., Magare, S., Monda, J., English, M., Fraser, H., Powell, J., & Paton, C. (2020). Digital health systems in Kenyan public hospitals: A mixed-methods survey. *BMC Medical Informatics and Decision Making, 20*, Article 2. https://doi.org/10.1186/s12911-019-1005-7',
    'proposal': 'Waweru, S. W. (2026). *Uhai Bora Hospital Management System: Project proposal (Chapters 1 to 3)* [Unpublished diploma project proposal]. KCA University.',
}


def shot_main(name, left=480, top=112):
    """Screenshot of the main area only (menu and top bar cropped away), so that text stays large on the page."""
    from PIL import Image
    src = shot(name)
    out_dir = os.path.join(SHOT, 'main')
    os.makedirs(out_dir, exist_ok=True)
    dst = os.path.join(out_dir, name + '.png')
    if not os.path.exists(dst) or os.path.getmtime(dst) < os.path.getmtime(src):
        with Image.open(src) as im:
            w, h = im.size
            im.crop((left, top, w, h)).save(dst)
    return dst


def landscape_figure(d, heading, intro, path, caption, label, level='h3', max_h=13.0):
    """A wide figure on its own landscape page with its heading; the introduction follows on the portrait page."""
    d.landscape()
    if heading:
        getattr(d, level)(heading)
    d.figure(path, caption, label=label, max_height_cm=max_h)
    d.portrait()
    if intro:
        d.p(intro)
