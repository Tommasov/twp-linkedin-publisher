<?php
$plugin_slug = 'twp-linkedin-publisher';
$main_file   = 'twp-linkedin-publisher.php';

echo "=== Avvio Packaging di Simple API Translator ===\n";

if ( ! file_exists( $main_file ) ) {
    die( "Errore: File principale del plugin '$main_file' non trovato nella cartella corrente.\n" );
}

$main_content = file_get_contents( $main_file );
if ( preg_match( '~^[ \t/*#]*Version:\s*([0-9.-]+)~mi', $main_content, $matches ) ) {
    $version = trim( $matches[1] );
    echo "Versione rilevata: v$version\n";
} else {
    $version = '1.0.0';
    echo "Attenzione: Impossibile rilevare la versione in '$main_file'. Impostata la versione di default v$version\n";
}

$builds_dir = 'builds';

if ( ! is_dir( $builds_dir ) ) {
    mkdir( $builds_dir, 0755, true );
    echo "Cartella '$builds_dir' creata.\n";
}

$zip_filename = "{$builds_dir}/{$plugin_slug}-v{$version}.zip";

if ( file_exists( $zip_filename ) ) {
    unlink( $zip_filename );
    echo "File ZIP precedente in '$builds_dir' rimosso.\n";
}

$zip = new ZipArchive();
if ( $zip->open( $zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
    die( "Errore: Impossibile creare il file ZIP '$zip_filename'.\n" );
}

$files_to_zip = array('twp-linkedin-publisher.php');

echo "Aggiunta dei file all'archivio ZIP...\n";

foreach ( $files_to_zip as $file ) {
    if ( file_exists( $file ) ) {
        $zip_path = $plugin_slug . '/' . $file;
        $zip->addFile( $file, $zip_path );
        echo " [+] $file -> $zip_path\n";
    } else {
        echo " [!] Attenzione: File '$file' non trovato. Saltato.\n";
    }
}

if ( $zip->close() ) {
    echo "\n=== Successo! ===\n";
    echo "Pacchetto creato con successo: \033[32m$zip_filename\033[0m\n";
    echo "Pronto per essere caricato su WordPress!\n";
} else {
    echo "Errore durante la chiusura del file ZIP.\n";
}
