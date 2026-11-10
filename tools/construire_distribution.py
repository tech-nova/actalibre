#!/usr/bin/env python3
"""Construit une livraison depuis une révision Git, sans copier de site installé."""
import argparse
import datetime
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tarfile
import zipfile

INTERDITS = {'install/configuration.php', '_config_init/connect.inc.php', 'tests/Install/LitteralConfigTest.php'}
RACINES_EXCLUES = {'vendor', 'tests', 'docs', 'graft', 'tmp', '_config', '_logs', '_backup', 'dist', '__pycache__'}
FICHIERS_EXCLUS = {'composer.lock', 'phpunit.xml.dist', 'tools/construire_distribution.py', '.gitattributes'}
MANIFESTE = 'inventaire-actalibre.json'


def git(*args):
    return subprocess.check_output(['git', *args])


def selectionner(revision):
    selection = []
    for ligne in git('ls-tree', '-rz', '--full-tree', revision).split(b'\0'):
        if not ligne:
            continue
        meta, brut = ligne.split(b'\t', 1)
        chemin = brut.decode('utf-8', errors='strict')
        # Avant toute lecture de blob, même si une règle d'exclusion aurait masqué le chemin.
        if chemin in INTERDITS:
            raise ValueError('Chemin interdit dans la révision : ' + chemin)
        parties = chemin.split('/')
        if any(x in RACINES_EXCLUES or x.startswith('.git') or x.startswith('.superpowers') or x.startswith('.php') for x in parties) or chemin in FICHIERS_EXCLUS or chemin.startswith('admin/_upload/') or chemin == MANIFESTE:
            continue
        if not chemin or any(x in ('', '.', '..') for x in parties) or '\\' in chemin or ':' in parties[0]:
            raise ValueError('Chemin de diffusion invalide')
        mode, type_objet, _ = meta.split(b' ')
        if mode not in (b'100644', b'100755') or type_objet != b'blob':
            raise ValueError('Lien ou objet non régulier dans la sélection : ' + chemin)
        selection.append(chemin)
    return sorted(selection)


def construire(revision, sortie):
    sha = git('rev-parse', '--verify', revision + '^{commit}').decode().strip()
    chemins = selectionner(sha)
    if 'composer.json' not in chemins:
        raise ValueError('composer.json absent de la sélection')
    # -- sépare les chemins des options ; aucune extraction d'un chemin exclu.
    archive = git('archive', '--format=tar', sha, '--', *chemins)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        contenus = {}
        for membre in tar:
            if membre.name not in chemins or not membre.isfile():
                continue
            flux = tar.extractfile(membre)
            if flux is None:
                raise ValueError('Archive Git incomplète')
            contenus[membre.name] = flux.read()
    if sorted(contenus) != chemins:
        raise ValueError('Archive Git incomplète')
    composer = json.loads(contenus['composer.json'])
    version, schema = composer['version'], composer['extra']['actalibre']['schema']
    if not all(isinstance(v, str) and re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', v) for v in (version, schema)):
        raise ValueError('Version ou schéma invalide')
    fichiers = sorted([*chemins, MANIFESTE])
    parents = set()
    for p in fichiers:
        parents.update(str(x) for x in PurePosixPath(p).parents if str(x) != '.')
    inventaire = {'format': 1, 'version': version, 'schema': schema, 'revision': sha, 'fichiers': fichiers, 'repertoires': sorted(parents)}
    contenus[MANIFESTE] = (json.dumps(inventaire, ensure_ascii=False, indent=2) + '\n').encode()
    timestamp = int(git('show', '-s', '--format=%ct', sha))
    date = datetime.datetime.fromtimestamp(max(timestamp, 315532800), datetime.timezone.utc).timetuple()[:6]
    sortie.mkdir(parents=True, exist_ok=True)
    destination = sortie / ('ActaLibre-' + version + '.zip')
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for p in fichiers:
            info = zipfile.ZipInfo(p, date_time=date)
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, contenus[p])
    empreinte = hashlib.sha256(destination.read_bytes()).hexdigest()
    (sortie / (destination.name + '.sha256')).write_text(empreinte + '  ' + destination.name + '\n')
    (sortie / MANIFESTE).write_bytes(contenus[MANIFESTE])
    print(str(destination) + '\nSHA-256 : ' + empreinte)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--sortie', type=Path, required=True)
    args = parser.parse_args()
    try:
        construire(args.revision, args.sortie)
    except (ValueError, KeyError, UnicodeError, OSError, subprocess.CalledProcessError) as erreur:
        print('Distribution refusée : ' + str(erreur), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
