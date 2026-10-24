"""Chapter 5 assembled."""
import ch5a
import ch5b
import ch5c

CH5_CITED = ['proposal', 'dennis', 'shaikh', 'sommerville', 'codd', 'elmasri', 'chen', 'muinga2020', 'nielsen', 'shneiderman', 'w3c', 'owasp', 'who2019']


def build(d):
    ch5a.build_purpose_overview(d)
    ch5a.build_architecture(d)
    ch5a.build_software_design(d)
    ch5b.build_database(d)
    ch5c.build_hmi(d)
    ch5c.build_integrity(d)
    ch5c.build_references_glossary(d, CH5_CITED)
