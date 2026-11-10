<?php
// Définition des index utilisables sr la base de données

// table, nom_index, cle, type, multi, obligatoire
$idx =  [
   ["nai3","LADATE",  "LADATE",              "BT","1","1","Date de l'acte"],
   ["nai3","IDNIM",   "IDNIM",               "BT","1","1","Identifiant Nimegue"],
   ["nai3","COM_DEP", "COMMUNE(10),DEPART(4)","BT","2","1","Commune et département"],
   ["nai3","NOM",     "NOM(10)",             "BT","1","1","Patronyme nouveau-né"],
   ["nai3","P_NOM",   "P_NOM(10)",           "BT","1","0","Patronyme du père"],
   ["nai3","M_NOM",   "M_NOM(10)",           "BT","1","0","Patronyme de la mère"],

   ["mar3","LADATE",  "LADATE",              "BT","1","1","Date de l'acte"],
   ["mar3","IDNIM",   "IDNIM",               "BT","1","1","Identifiant Nimegue"],
   ["mar3","COM_DEP", "COMMUNE(10),DEPART(4)","BT","2","1","Commune et département"],
   ["mar3","NOM",     "NOM(10)",             "BT","1","1","Patronyme époux"],
   ["mar3","C_NOM",   "C_NOM(10)",           "BT","1","0","Patronyme épouse"],
   ["mar3","P_NOM",   "P_NOM(10)",           "BT","1","0","Patronyme du père de l'époux"],
   ["mar3","M_NOM",   "M_NOM(10)",           "BT","1","0","Patronyme de la mère de l'époux"],
   ["mar3","CP_NOM",  "CP_NOM(10)",          "BT","1","0","Patronyme du père de l'épouse"],
   ["mar3","CM_NOM",  "CM_NOM(10)",          "BT","1","0","Patronyme de la mère de l'épouse"],
   ["mar3","ORI",     "ORI(12)",             "BT","1","0","Origine de l'époux"],
   ["mar3","C_ORI",   "C_ORI(12)",           "BT","1","0","Origine de l'épouse"],

   ["dec3","LADATE",  "LADATE",              "BT","1","1","Date de l'acte"],
   ["dec3","IDNIM",   "IDNIM",               "BT","1","1","Identifiant Nimegue"],
   ["dec3","COM_DEP", "COMMUNE(10),DEPART(4)","BT","2","1","Commune et département"],
   ["dec3","NOM",     "NOM(10)",             "BT","1","1","Patronyme du décédé"],
   ["dec3","C_NOM",   "C_NOM(10)",           "BT","1","0","Patronyme du conjoint"],
   ["dec3","P_NOM",   "P_NOM(10)",           "BT","1","0","Patronyme du père"],
   ["dec3","M_NOM",   "M_NOM(10)",           "BT","1","0","Patronyme de la mère"],
   ["dec3","ORI",     "ORI(12)",             "BT","1","0","Origine du décédé"],

   ["div3","LADATE",  "LADATE",              "BT","1","1","Date de l'acte"],
   ["div3","IDNIM",   "IDNIM",               "BT","1","1","Identifiant Nimegue"],
   ["div3","COM_DEP_LIB","COMMUNE(10),DEPART(4),LIBELLE(12)","BT","2","1","Commune et départ. & libellé"],
   ["div3","NOM",     "NOM(10)",             "BT","1","1","Patronyme du 1er intervenant"],
   ["div3","C_NOM",   "C_NOM(10)",           "BT","1","0","Patronyme du 2d intervenant"],
   ["div3","P_NOM",   "P_NOM(10)",           "BT","1","0","Patronyme du père du 1er it."],
   ["div3","M_NOM",   "M_NOM(10)",           "BT","1","0","Patronyme de la mère du 1er it."],
   ["div3","CP_NOM",  "CP_NOM(10)",          "BT","1","0","Patronyme du père du 2d it."],
   ["div3","CM_NOM",  "CM_NOM(10)",          "BT","1","0","Patronyme de la mère du 2d it."],
   ["div3","ORI",     "ORI(12)",             "BT","1","0","Origine du 1er intervanant"],
   ["div3","C_ORI",   "C_ORI(12)",           "BT","1","0","Origine du 2d intervanant"],
  ];

//------------------------------------------------------------------------------


?>