// Géolocalisation des localités (administration) : sélection des lignes affichées et fenêtre de progression.
// Le serveur impose droits, débit et règles d'enregistrement ; ce script ne fait que piloter et afficher.
(function () {
	'use strict';

	var LIBELLES = { N: 'Non définie', I: 'Introuvable', A: 'Automatique', M: 'Manuelle' };
	var NOUVEAU_STATUT = { trouvee: 'A', introuvable: 'I' };
	var BILAN = {
		introuvable: 'introuvable',
		sans_nouveau: 'aucun nouveau résultat, coordonnées automatiques conservées',
		manuelle: 'coordonnées manuelles conservées',
		modifiee: 'fiche modifiée pendant la recherche : à vérifier',
		supprimee: 'fiche supprimée : à vérifier'
	};
	var dialogue, api, jeton;

	function el(id) {
		return document.getElementById(id);
	}

	function pluriel(n, singulier, plurielTexte) {
		return n + ' ' + (n > 1 ? plurielTexte : singulier);
	}

	function annoncer(texte) {
		var zone = el('geoloc-annonce');
		if (zone) {
			zone.textContent = texte;
		}
	}

	// Une page HTML (connexion, erreur du serveur) n'est jamais prise pour un succès.
	function lireReponse(reponse) {
		var type = reponse.headers.get('Content-Type') || '';
		if (type.indexOf('application/json') !== 0) {
			return Promise.reject({ erreur: 'session', message: 'Votre session a expiré. Reconnectez-vous, puis reprenez la mise à jour.' });
		}
		return reponse.json().then(function (donnees) {
			return reponse.ok ? donnees : Promise.reject(donnees);
		});
	}

	function reseau() {
		return Promise.reject({ erreur: 'reseau', message: 'Le site ne répond pas. Vérifiez votre connexion, puis reprenez la mise à jour.' });
	}

	function envoyer(action, champs) {
		var corps = new URLSearchParams();
		corps.append('action', action);
		corps.append('jeton', jeton);
		Object.keys(champs || {}).forEach(function (nom) {
			var valeur = champs[nom];
			if (Array.isArray(valeur)) {
				valeur.forEach(function (v) { corps.append(nom + '[]', v); });
			} else {
				corps.append(nom, valeur);
			}
		});
		return fetch(api, { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(lireReponse, reseau);
	}

	function demander(action, champs) {
		var parametres = new URLSearchParams(champs || {});
		parametres.append('action', action);
		return fetch(api + '?' + parametres.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(lireReponse, reseau);
	}

	function majLigne(dernier) {
		var statut = NOUVEAU_STATUT[dernier.issue];
		var ligne = document.querySelector('tr[data-id="' + Number(dernier.id) + '"]');
		if (!statut || !ligne) {
			return;
		}
		ligne.setAttribute('data-statut', statut);
		var cellule = ligne.querySelector('.geoloc-statut');
		if (cellule) {
			cellule.textContent = LIBELLES[statut];
		}
	}

	var fenetre = {
		declencheur: null,
		ouvrir: function (declencheur, total) {
			this.declencheur = declencheur;
			el('geoloc-bilan').textContent = '';
			['trouvee', 'introuvable', 'manuelle', 'a_verifier'].forEach(function (cle) { el('geoloc-n-' + cle).textContent = '0'; });
			el('geoloc-courant').textContent = '—';
			el('geoloc-remplacer').hidden = true;
			this.progression(0, total);
			this.etat('Préparation de la mise à jour…', true);
			this.boutons('pause');
			dialogue.showModal();
			el('geoloc-pause').focus();
		},
		fermer: function () {
			if (dialogue.open) {
				dialogue.close();
			}
			if (this.declencheur) {
				this.declencheur.focus();
			}
		},
		// La zone d'état est annoncée : elle ne change qu'aux étapes, pas à chaque lieu.
		etat: function (texte, actif) {
			var zone = el('geoloc-etat');
			if (zone.textContent !== texte) {
				zone.textContent = texte;
			}
			dialogue.classList.toggle('actif', actif);
		},
		progression: function (traites, total) {
			var barre = el('geoloc-barre');
			barre.max = Math.max(total, 1);
			barre.value = traites;
			el('geoloc-avance').textContent = traites + ' / ' + pluriel(total, 'localité traitée', 'localités traitées');
		},
		boutons: function (mode) {
			el('geoloc-pause').hidden = mode !== 'pause';
			el('geoloc-reprendre-dialogue').hidden = mode !== 'reprendre';
		},
		vue: function (vue, nouveau) {
			Object.keys(vue.compteurs).forEach(function (cle) { el('geoloc-n-' + cle).textContent = String(vue.compteurs[cle]); });
			this.progression(vue.traites, vue.total);
			if (vue.dernier && nouveau) {
				el('geoloc-courant').textContent = vue.dernier.libelle;
				majLigne(vue.dernier);
				var texte = BILAN[vue.dernier.issue];
				if (texte) {
					var ligne = document.createElement('li');
					ligne.textContent = vue.dernier.libelle + ' : ' + texte + (vue.dernier.cache ? ' (réponse récente réutilisée)' : '');
					el('geoloc-bilan').appendChild(ligne);
				}
			}
		}
	};

	function heure(horodatage) {
		return new Date(horodatage * 1000).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
	}

	function messageSuspension(vue) {
		var avance = vue.traites + ' / ' + pluriel(vue.total, 'localité traitée', 'localités traitées') + '.';
		if (vue.cause === 'debit') {
			return 'Le service Nominatim demande de patienter : mise à jour suspendue, reprise possible après ' + heure(vue.reprise) + '. ' + avance;
		}
		if (vue.cause === 'panne') {
			return 'Le service de géocodage ne répond pas : mise à jour suspendue. Les localités restantes n\'ont pas été traitées. ' + avance;
		}
		return 'Mise à jour suspendue. Les résultats déjà enregistrés sont conservés. ' + avance;
	}

	function bilanFinal(vue) {
		var c = vue.compteurs;
		return 'Mise à jour terminée : ' + pluriel(c.trouvee, 'trouvée', 'trouvées') + ', ' + pluriel(c.introuvable, 'introuvable', 'introuvables')
			+ ', ' + pluriel(c.manuelle, 'manuelle conservée', 'manuelles conservées') + ', ' + c.a_verifier + ' à vérifier.';
	}

	var pilote = {
		travail: null,
		actif: false,
		pause: false,
		traitesVus: 0,
		demarrer: function (vue) {
			this.travail = vue.travail;
			this.pause = false;
			this.traitesVus = vue.traites;
			fenetre.vue(vue, false);
			this.recevoir(vue);
		},
		boucle: function () {
			var self = this;
			if (self.pause || self.actif) {
				return;
			}
			self.actif = true;
			fenetre.etat('Recherche en cours auprès de Nominatim…', true);
			envoyer('avancer', { travail: self.travail }).then(function (vue) {
				self.actif = false;
				self.recevoir(vue);
			}, function (erreur) {
				self.actif = false;
				if (erreur.erreur === 'occupe') {
					setTimeout(function () { self.boucle(); }, 3000);
					return;
				}
				self.interrompre(erreur.message || 'La mise à jour a été interrompue.');
			});
		},
		recevoir: function (vue) {
			fenetre.vue(vue, vue.traites > this.traitesVus);
			this.traitesVus = vue.traites;
			if (vue.etat === 'pret' || vue.etat === 'actif') {
				this.boucle();
				return;
			}
			this.pause = true;
			if (vue.etat === 'termine') {
				fenetre.etat(bilanFinal(vue), false);
				fenetre.boutons('fin');
				el('geoloc-fermer').focus();
			} else if (vue.etat === 'suspendu') {
				fenetre.etat(messageSuspension(vue), false);
				fenetre.boutons('reprendre');
			} else {
				fenetre.etat('Cette mise à jour a expiré. Les résultats déjà enregistrés sont conservés ; lancez une nouvelle sélection.', false);
				fenetre.boutons('fin');
			}
		},
		interrompre: function (message) {
			this.pause = true;
			fenetre.etat(message, false);
			fenetre.boutons(this.travail ? 'reprendre' : 'fin');
		},
		suspendre: function () {
			var self = this;
			self.pause = true;
			fenetre.boutons('reprendre');
			fenetre.etat('Suspension demandée : la recherche en cours se termine.', false);
			return envoyer('suspendre', { travail: self.travail }).then(function (vue) {
				fenetre.vue(vue, vue.traites > self.traitesVus);
				self.traitesVus = vue.traites;
				fenetre.etat(vue.etat === 'termine' ? bilanFinal(vue) : messageSuspension(vue), false);
				if (vue.etat === 'termine') {
					fenetre.boutons('fin');
				}
			}, function (erreur) {
				fenetre.etat(erreur.message, false);
			});
		},
		reprendre: function () {
			var self = this;
			fenetre.boutons('pause');
			fenetre.etat('Reprise de la mise à jour…', true);
			envoyer('reprendre', { travail: self.travail }).then(function (vue) {
				self.pause = false;
				self.recevoir(vue);
			}, function (erreur) {
				self.interrompre(erreur.message);
			});
		}
	};

	function fermerDialogue() {
		var enCours = pilote.travail !== null && !pilote.pause;
		var suite = enCours ? pilote.suspendre() : Promise.resolve();
		fenetre.fermer();
		annoncer(enCours
			? 'Mise à jour suspendue. Les résultats déjà enregistrés sont conservés ; « Reprendre la mise à jour » la poursuit.'
			: 'Fenêtre de mise à jour fermée.');
		suite.then(verifierReprise);
	}

	function verifierReprise() {
		var zone = el('geoloc-reprise');
		if (!zone) {
			return;
		}
		demander('actif').then(function (reponse) {
			var vue = reponse.travail;
			if (!vue || dialogue.open) {
				zone.hidden = true;
				return;
			}
			el('geoloc-reprise-texte').textContent = 'Une mise à jour des coordonnées est suspendue (' + vue.traites + ' / '
				+ pluriel(vue.total, 'localité traitée', 'localités traitées') + ').';
			zone.hidden = false;
			el('geoloc-reprendre').onclick = function () {
				zone.hidden = true;
				fenetre.ouvrir(el('geoloc-reprendre'), vue.total);
				fenetre.vue(vue, false);
				pilote.travail = vue.travail;
				pilote.traitesVus = vue.traites;
				pilote.reprendre();
			};
		}, function () {
			zone.hidden = true;
		});
	}

	function initListe(formulaire) {
		var cases = Array.prototype.slice.call(formulaire.querySelectorAll('input[name="ids[]"]'));
		var tout = el('geoloc-tout');
		var bouton = el('geoloc-maj');
		formulaire.classList.add('geoloc-actif');
		Array.prototype.forEach.call(document.querySelectorAll('.geoloc-commandes, .geoloc-pied'), function (n) { n.hidden = false; });

		function statut(caseLigne) {
			return caseLigne.closest('tr').getAttribute('data-statut');
		}
		function choisies() {
			return cases.filter(function (c) { return c.checked; });
		}
		function actualiser() {
			var n = choisies().length;
			var manuelles = choisies().filter(function (c) { return statut(c) === 'M'; }).length;
			tout.checked = n > 0 && n === cases.length;
			tout.indeterminate = n > 0 && n < cases.length;
			bouton.disabled = n === 0;
			el('geoloc-compte').textContent = n === 0
				? 'Aucune localité sélectionnée : cochez au moins une ligne.'
				: pluriel(n, 'localité sélectionnée', 'localités sélectionnées')
					+ (manuelles === 0 ? '.' : ', dont ' + pluriel(manuelles, 'manuelle, qui ne sera pas modifiée.', 'manuelles, qui ne seront pas modifiées.'));
		}
		function cocher(filtre) {
			cases.forEach(function (c) {
				if (filtre(c)) {
					c.checked = true;
				}
			});
			actualiser();
		}

		tout.addEventListener('change', function () {
			var etat = tout.checked;
			cases.forEach(function (c) { c.checked = etat; });
			actualiser();
		});
		el('geoloc-cocher-tout').addEventListener('click', function () { cocher(function () { return true; }); });
		el('geoloc-cocher-sans').addEventListener('click', function () {
			cocher(function (c) { var s = statut(c); return s === 'N' || s === 'I'; });
		});
		cases.forEach(function (c) { c.addEventListener('change', actualiser); });
		bouton.addEventListener('click', function () {
			var ids = choisies().map(function (c) { return c.value; });
			fenetre.ouvrir(bouton, ids.length);
			pilote.travail = null;
			envoyer('creer', { ids: ids }).then(function (vue) {
				pilote.demarrer(vue);
			}, function (erreur) {
				pilote.interrompre(erreur.message || 'La mise à jour n\'a pas pu commencer.');
			});
		});
		actualiser();
		verifierReprise();
	}

	var MESSAGES_FICHE = {
		introuvable: 'Nominatim ne connaît pas ce lieu. Les coordonnées actuelles sont conservées.',
		sans_nouveau: 'Aucun nouveau résultat : les coordonnées automatiques actuelles sont conservées.',
		manuelle: 'Cette fiche est devenue manuelle entre-temps : rechargez la page.',
		modifiee: 'La fiche a été modifiée pendant la recherche : rechargez la page pour voir sa version actuelle.',
		supprimee: 'Cette fiche a été supprimée.'
	};

	// Reporte la fiche enregistrée dans le formulaire sans toucher aux commentaires en cours de saisie.
	function appliquerFiche(fiche) {
		var lon = el('lon');
		var lat = el('lat');
		lon.value = lon.defaultValue = fiche.lon === null ? '' : fiche.lon;
		lat.value = lat.defaultValue = fiche.lat === null ? '' : fiche.lat;
		el('version_statut').value = fiche.statut;
		el('version_lon').value = fiche.lon === null ? '' : fiche.lon;
		el('version_lat').value = fiche.lat === null ? '' : fiche.lat;
		el('geoloc-fiche-statut').textContent = LIBELLES[fiche.statut] || fiche.statut;
		if (fiche.lon !== null && fiche.lat !== null) {
			document.dispatchEvent(new CustomEvent('geoloc:coordonnees', { detail: { lon: parseFloat(fiche.lon), lat: parseFloat(fiche.lat) } }));
		}
	}

	function resultatFiche(reponse) {
		fenetre.progression(1, 1);
		if (reponse.issue === 'trouvee') {
			appliquerFiche(reponse.fiche);
			fenetre.etat('Coordonnées trouvées et enregistrées : ' + reponse.fiche.lon + ', ' + reponse.fiche.lat
				+ (reponse.cache ? ' (réponse récente réutilisée)' : '') + '. Vérifiez la position sur la carte.', false);
			return;
		}
		if (reponse.issue === 'proposition') {
			var valider = el('geoloc-remplacer');
			fenetre.etat('Nominatim propose ' + reponse.proposition.lon + ', ' + reponse.proposition.lat + '. Les coordonnées manuelles actuelles ('
				+ reponse.fiche.lon + ', ' + reponse.fiche.lat + ') ne sont pas modifiées sans votre validation.', false);
			valider.hidden = false;
			valider.onclick = function () {
				valider.hidden = true;
				fenetre.etat('Enregistrement…', true);
				envoyer('remplacer', {
					id: reponse.fiche.id, lon: reponse.proposition.lon, lat: reponse.proposition.lat,
					version_statut: reponse.fiche.statut,
					version_lon: reponse.fiche.lon === null ? '' : reponse.fiche.lon,
					version_lat: reponse.fiche.lat === null ? '' : reponse.fiche.lat
				}).then(function (suite) {
					if (suite.issue === 'trouvee') {
						appliquerFiche(suite.fiche);
						fenetre.etat('Coordonnées manuelles remplacées par le résultat de Nominatim. Vérifiez la position sur la carte.', false);
					} else {
						fenetre.etat(MESSAGES_FICHE[suite.issue] || 'Rien n\'a été enregistré.', false);
					}
				}, function (erreur) {
					fenetre.etat(erreur.message, false);
				});
			};
			valider.focus();
			return;
		}
		if ((reponse.issue === 'introuvable' || reponse.issue === 'sans_nouveau') && reponse.fiche) {
			appliquerFiche(reponse.fiche);
		}
		fenetre.etat(MESSAGES_FICHE[reponse.issue] || 'Recherche terminée.', false);
	}

	function initFiche(bouton) {
		bouton.hidden = false;
		bouton.addEventListener('click', function () {
			var lon = el('lon');
			var lat = el('lat');
			var message = el('geoloc-fiche-message');
			if (lon.value !== lon.defaultValue || lat.value !== lat.defaultValue) {
				message.textContent = 'Vous avez modifié les coordonnées sans les enregistrer : enregistrez la fiche, ou cliquez sur « Effacer », avant de lancer la recherche.';
				return;
			}
			message.textContent = '';
			fenetre.ouvrir(bouton, 1);
			fenetre.boutons('fin');
			fenetre.etat('Recherche en cours auprès de Nominatim…', true);
			envoyer('rechercher', { id: bouton.getAttribute('data-id') }).then(resultatFiche, function (erreur) {
				fenetre.etat(erreur.message || 'La recherche n\'a pas abouti. Les coordonnées actuelles sont conservées.', false);
			});
		});
	}

	function demarrer() {
		dialogue = el('geoloc-dialogue');
		if (!dialogue || typeof dialogue.showModal !== 'function') {
			return;
		}
		api = dialogue.getAttribute('data-api');
		jeton = dialogue.getAttribute('data-jeton');
		el('geoloc-pause').addEventListener('click', function () { pilote.suspendre(); });
		el('geoloc-reprendre-dialogue').addEventListener('click', function () { pilote.reprendre(); });
		el('geoloc-fermer').addEventListener('click', fermerDialogue);
		dialogue.addEventListener('cancel', function (evenement) {
			evenement.preventDefault();
			fermerDialogue();
		});
		var formulaire = el('geoloc-selection');
		if (formulaire) {
			initListe(formulaire);
		}
		var recherche = el('geoloc-rechercher');
		if (recherche) {
			initFiche(recherche);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', demarrer);
	} else {
		demarrer();
	}
})();
