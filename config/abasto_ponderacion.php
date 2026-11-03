<?php
// config/abasto_ponderacion.php
// Medicamentos del Excel docs/abasto_maquina.xlsx (hoja "Hoja1", columnas
// "Nombre Producto" y "Volumen a desplazar en 12 meses"). La ponderación de
// cada uno se calcula en código como volumen / suma de volúmenes, igual que
// la columna F del Excel (=E7/SUM($E$7:$E$26)).
//
// 'patrones': textos (sin acentos, minúsculas) que se buscan dentro de
// vendingm.productos.nombre / nombre_comercial / sustancia_activa para saber
// a qué renglón del Excel corresponde un producto. Un renglón con varias
// sustancias ("Pantoprazol o Rabeprazol u Omeprazol") lleva varios patrones.
// Si un producto de una máquina no coincide con ninguno, queda con
// ponderación 0 (sigue apareciendo en el detalle, pero no suma al riesgo).
return [
    ['nombre' => 'Metformina',                          'patrones' => ['metformina'],                                   'volumen' => 4246276],
    ['nombre' => 'Atorvastatina',                       'patrones' => ['atorvastatina'],                                'volumen' => 2463762],
    ['nombre' => 'Hidroclorotiazida',                   'patrones' => ['hidroclorotiazida'],                            'volumen' => 2058925],
    ['nombre' => 'Losartan',                            'patrones' => ['losartan'],                                     'volumen' => 1973411],
    ['nombre' => 'Telmisartan',                         'patrones' => ['telmisartan'],                                  'volumen' => 1733351],
    ['nombre' => 'Enalapril',                           'patrones' => ['enalapril'],                                    'volumen' => 1669964],
    ['nombre' => 'Metoprolol',                          'patrones' => ['metoprolol'],                                   'volumen' => 1292417],
    ['nombre' => 'Pantoprazol o Rabeprazol u Omeprazol', 'patrones' => ['pantoprazol', 'rabeprazol', 'omeprazol'],      'volumen' => 755550],
    ['nombre' => 'Amlodipino',                          'patrones' => ['amlodipino'],                                   'volumen' => 727798],
    ['nombre' => 'Nifedipino',                          'patrones' => ['nifedipino'],                                   'volumen' => 654318],
    ['nombre' => 'Bezafibrato',                         'patrones' => ['bezafibrato'],                                  'volumen' => 582249],
    ['nombre' => 'Paracetamol',                         'patrones' => ['paracetamol'],                                  'volumen' => 540356],
    ['nombre' => 'Linagliptina',                        'patrones' => ['linagliptina'],                                 'volumen' => 435965],
    ['nombre' => 'Furosemida',                          'patrones' => ['furosemida'],                                   'volumen' => 414282],
    ['nombre' => 'Dapagliflozina',                      'patrones' => ['dapagliflozina'],                               'volumen' => 396347],
    ['nombre' => 'Glibenclamida',                       'patrones' => ['glibenclamida'],                                'volumen' => 280800],
    ['nombre' => 'Acido Acetilsalicilico',              'patrones' => ['acido acetilsalicilico', 'acetilsalicilico'],   'volumen' => 245511],
    ['nombre' => 'Ibuprofeno',                          'patrones' => ['ibuprofeno'],                                   'volumen' => 134078],
    ['nombre' => 'Sitagliptina',                        'patrones' => ['sitagliptina'],                                 'volumen' => 110678],
    ['nombre' => 'Verapamilo',                          'patrones' => ['verapamilo'],                                   'volumen' => 48515],
];
