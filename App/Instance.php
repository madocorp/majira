<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Keeps one running MaJira; later starts pass their ticket argument to it. */
class Instance {

  private const SOCKET_FILE = 'majira.sock';

  private static mixed $server = null;
  private static string $target = '';

  /** Send the target to a running instance; remember it when none is running. */
  public static function forward(string $target): bool {
    $client = @stream_socket_client('unix://' . self::socketPath(), $errorCode, $errorMessage, 1);
    if ($client === false) {
      self::$target = $target;
      return false;
    }
    fwrite($client, $target . "\n");
    fclose($client);
    return true;
  }

  /** Return the target given to this instance at start, once. */
  public static function takeTarget(): string {
    $target = self::$target;
    self::$target = '';
    return $target;
  }

  /** Accept targets from later starts; failure only disables forwarding. */
  public static function listen(): void {
    $path = self::socketPath();
    // forward() already failed, so a socket file here is stale.
    if (file_exists($path)) {
      unlink($path);
    }
    $server = @stream_socket_server('unix://' . $path, $errorCode, $errorMessage);
    if ($server === false) {
      return;
    }
    stream_set_blocking($server, false);
    self::$server = $server;
    register_shutdown_function(function() use ($path): void {
      fclose(self::$server);
      @unlink($path);
    });
  }

  /** Return the targets sent since the last call, without waiting. */
  public static function receive(): array {
    $targets = [];
    while (self::$server !== null) {
      $read = [self::$server];
      $write = $except = null;
      if (stream_select($read, $write, $except, 0) < 1) {
        break;
      }
      $client = @stream_socket_accept(self::$server, 0);
      if ($client === false) {
        break;
      }
      stream_set_timeout($client, 1);
      $targets[] = trim((string)stream_get_contents($client));
      fclose($client);
    }
    return $targets;
  }

  private static function socketPath(): string {
    return AppData::file(self::SOCKET_FILE);
  }

}
