<?php
// Czech stations that announce platforms late, as seen on the SŽ boards on 2 Nov 2026.
// sz:    Key of the station's board on provoz.spravazeleznic.cz/Tabule
// czptt: CZPTT stop id in Transitous (the SR70 station code without the leading "54")
return [
    'praha-hl-n'        => ['name' => 'Praha hl.n.',          'sz' => 3444, 'czptt' => '57076'],
    'praha-masarykovo'  => ['name' => 'Praha Masarykovo n.',  'sz' => 3449, 'czptt' => '57236'],
    'brno-hl-n'         => ['name' => 'Brno hl.n.',           'sz' => 455,  'czptt' => '33295'],
    'olomouc-hl-n'      => ['name' => 'Olomouc hl.n.',        'sz' => 3073, 'czptt' => '34362'],
    'prerov'            => ['name' => 'Přerov',               'sz' => 3538, 'czptt' => '34662'],
    'breclav'           => ['name' => 'Břeclav',              'sz' => 489,  'czptt' => '33425'],
    'ostrava-svinov'    => ['name' => 'Ostrava-Svinov',       'sz' => 3161, 'czptt' => '34434'],
    'pardubice-hl-n'    => ['name' => 'Pardubice hl.n.',      'sz' => 3223, 'czptt' => '53613'],
    'ceska-trebova'     => ['name' => 'Česká Třebová',        'sz' => 699,  'czptt' => '53913'],
    'hradec-kralove-hl-n' => ['name' => 'Hradec Králové hl.n.', 'sz' => 1529, 'czptt' => '53120'],
    'liberec'           => ['name' => 'Liberec',              'sz' => 2358, 'czptt' => '54212'],
    'ceske-budejovice'  => ['name' => 'České Budějovice',     'sz' => 626,  'czptt' => '73282'],
    // Added on 8 Nov on request; Plzeň mostly announces platforms early.
    'plzen-hl-n'        => ['name' => 'Plzeň hl.n.',          'sz' => 3341, 'czptt' => '73275'],
    'decin-hl-n'        => ['name' => 'Děčín hl.n.',          'sz' => 747,  'czptt' => '55659'],
];
