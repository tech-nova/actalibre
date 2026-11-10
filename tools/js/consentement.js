// Réglages de tarteaucitron : la carte OpenStreetMap est le seul service tiers d'ActaLibre.
(function () {
	'use strict';
	var reglages = JSON.parse(document.getElementById('consentement-reglages').textContent);
	window.tarteaucitronForceLanguage = 'fr';
	if (reglages.message !== '') {
		window.tarteaucitronCustomText = { alertBigPrivacy: reglages.message };
	}
	tarteaucitron.init({
		privacyUrl: reglages.adresse,
		orientation: reglages.position,
		cookieName: 'tarteaucitron',
		highPrivacy: true,
		googleConsentMode: false,
		pianoConsentMode: false,
		bingConsentMode: false,
		piwikConsentMode: false
	});
	(tarteaucitron.job = tarteaucitron.job || []).push('openstreetmap');
}());
