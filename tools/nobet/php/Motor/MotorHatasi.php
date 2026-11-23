<?php

namespace KodmobTools\Nobet\Motor;

use RuntimeException;

/** Döngü kurulamadı (ayarlar çelişiyor ya da bir kural bütün adayları eledi); mesaj yöneticiye gösterilir. */
class MotorHatasi extends RuntimeException {}
