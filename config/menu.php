<?php
// Config menu sesuai permintaan
$menuConfig = [
    'admin' => [
        [
            'title' => 'Dashboard',
            'icon' => 'fas fa-tachometer-alt',
            'url' => 'dashboardAdmin.php',
            'active' => ['dashboardAdmin.php']
        ],
        [
            'title' => 'Manajemen User',
            'icon' => 'fas fa-users-cog',
            'submenu' => [
                [
                    'title' => 'Data Admin',
                    'url' => 'dataStaff.php',
                    'active' => ['dataStaff.php']
                ],
                [
                    'title' => 'Data Guru',
                    'url' => 'dataGuru.php',
                    'active' => ['dataGuru.php']
                ],
                [
                    'title' => 'Data Siswa',
                    'url' => 'dataSiswa.php',
                    'active' => ['dataSiswa.php']
                ],
            ]
        ],
        [
            'title' => 'Manajemen Siswa',
            'icon' => 'fas fa-users-cog',
            'submenu' => [
                [
                    'title' => 'Laporan Penilaian',
                    'icon' => 'fas fa-clipboard-list',
                    'url' => 'dataNilai.php',
                    'active' => ['dataNilai.php']  
                ],
                 [
                    'title' => 'Jadwal Siswa',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'jadwalSiswa.php',
                    'active' => ['jadwalSiswa.php']  
                ],
                 [
                    'title' => 'Rekap Absensi Siswa',
                    'icon' => 'fas fa-school',
                    'url' => 'rekapSiswa.php',
                    'active' => ['rekapSiswa.php']
                ],
            ]
            
        ],
        // [
        //     'title' => 'Laporan',
        //     'icon' => 'fas fa-chart-bar',
        //     'url' => 'laporan.php',
        //     'active' => ['laporan.php']
        // ],
        [
            'title' => 'Pembayaran',
            'icon' => 'fas fa-money-bill-wave',
            'url' => 'pembayaran.php',
            'active' => ['pembayaran.php']
        ],
        [
            'title' => 'Pengumuman',
            'icon' => 'fas fa-bullhorn ',
            'url' => 'pengumuman.php',
            'active' => ['pengumuman.php']
        ],
        [
            'title' => 'Pengaturan',
            'icon' => 'fas fa-cog',
            'url' => 'pengaturan.php',
            'active' => ['pengaturan.php']
        ],
        [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '../logout.php',
            'class' => 'menu-item flex items-center px-4 py-3 text-white hover:bg-white hover:text-red-800 transition duration-300 '
        ]
    ],

    'guru' => [
        [
            'title' => 'Dashboard',
            'icon' => 'fas fa-home',
            'url' => 'dashboardGuru.php',
            'active' => ['dashboardGuru.php']
        ],
        [
            'title' => 'Manajemen Siswa',
            'icon' => 'fas fa-users-cog',
            'submenu' => [
                [
                    'title' => 'Data Siswa',
                    'icon' => 'fas fa-users',
                    'url' => 'dataSiswa.php',
                    'active' => ['dataSiswa.php']
                ],
                [
                    'title' => 'Jadwal Siswa',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'jadwalSiswa.php',
                    'active' => ['jadwalSiswa.php']
                ],
                [
                    'title' => 'Absensi Siswa',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'absensiSiswa.php',
                    'active' => ['absensiSiswa.php']
                ],
                [
                    'title' => 'Rekap Absensi Siswa',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'rekapAbsensi.php',
                    'active' => ['rekapAbsensi.php']
                ],
            ]
            
        ],
        [
            'title' => 'Penilaian Siswa',
            'icon' => 'fas fa-users-cog',
            'submenu' => [
                [
                    'title' => 'Input Penilaian',
                    'icon' => 'fas fa-edit',
                    'url' => 'inputNilai.php',
                    'active' => ['inputNilai.php']
                ],
                [
                    'title' => 'Riwayat & Laporan Nilai',
                    'icon' => 'fas fa-history',
                    'url' => 'riwayat.php',
                    'active' => ['riwayat.php']
                ],
                // [
                //     'title' => 'Laporan',
                //     'icon' => 'fas fa-chart-bar',
                //     'url' => 'laporanGuru.php',
                //     'active' => ['laporanGuru.php']
                // ],
            ]
            
        ],
        [
            'title' => 'Pengumuman',
            'icon' => 'fas fa-bullhorn ',
            'url' => 'pengumumanGuru.php',
            'active' => ['pengumumanGuru.php']
        ],
        [
            'title' => 'Profile',
            'icon' => 'fas fa-user',
            'url' => 'profile.php',
            'active' => ['profile.php']
        ],
        [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '../logout.php',
            'class' => 'menu-item flex items-center px-4 py-3 text-white hover:bg-white hover:text-red-800 transition duration-300 '
        ]
    ],

    'orangtua' => [
        [
            'title' => 'Dashboard',
            'icon' => 'fas fa-home',
            'url' => 'dashboardOrtu.php',
            'active' => ['dashboardOrtu.php']
        ],
        [
            'title' => 'Nilai Anak',
            'icon' => 'fas fa-chart-line',
            'submenu' => [
                [
                    'title' => 'Laporan Mingguan',
                    'url' => 'laporanMingguan.php',
                    'active' => ['laporanMingguan.php']
                ],
                [
                    'title' => 'Perkembangan Anak',
                    'url' => 'perkembanganAnak.php',
                    'active' => ['perkembanganAnak.php']
                ],
                [
                    'title' => 'Riwayat Penilaian',
                    'url' => 'riwayatNilai.php',
                    'active' => ['riwayatNilai.php']
                ]
            ]
        ],
        [
            'title' => 'Jadwal Anak',
            'icon' => 'fas fa-calendar-alt',
            'submenu' => [
                [
                    'title' => 'Abensi Anak',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'absensiAnak.php',
                    'active' => ['absensiAnak.php']
                ],
                [
                    'title' => 'Jadwal Anak',
                    'icon' => 'fas fa-calendar-alt',
                    'url' => 'jadwalAnak.php',
                    'active' => ['jadwalAnak.php']
                ],
                
            ]
        ],
        
        [
            'title' => 'Pembayaran',
            'icon' => 'fas fa-money-bill-wave',
            'url' => 'pembayaranOrtu.php',
            'active' => ['pembayaranOrtu.php']
        ],
        
        [
            'title' => 'Pengumuman',
            'icon' => 'fas fa-bullhorn ',
            'url' => 'pengumumanOrtu.php',
            'active' => ['pengumumanOrtu.php']
        ],
        [
            'title' => 'Profile Saya',
            'icon' => 'fas fa-user-cog',
            'url' => 'profile.php',
            'active' => ['profile.php']
        ],
        [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '../logout.php',
            'class' => 'menu-item flex items-center px-4 py-3 text-white hover:bg-white hover:text-red-800 transition duration-300 '
        ]
    ]
];
?>