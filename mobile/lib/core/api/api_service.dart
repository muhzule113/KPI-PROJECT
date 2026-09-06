import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiException implements Exception {
  final int statusCode;
  final String message;
  final String? code;
  final Map<String, dynamic>? errors;

  const ApiException({
    required this.statusCode,
    required this.message,
    this.code,
    this.errors,
  });

  bool get isUnauthorized => statusCode == 401;
  bool get isConflict => statusCode == 409;

  @override
  String toString() => message;
}

class ApiService {
  // Bisa dioverride saat run: --dart-define=API_BASE_URL=http://IP_LAPTOP:8000/api/v1
  static String get baseUrl {
    const configuredUrl = String.fromEnvironment('API_BASE_URL');
    if (kReleaseMode) {
      if (configuredUrl.isEmpty || !configuredUrl.startsWith('https://')) {
        throw StateError('Build release wajib memakai API_BASE_URL HTTPS.');
      }
      return configuredUrl;
    }
    if (configuredUrl.isNotEmpty) return configuredUrl;
    if (kIsWeb) return 'http://localhost:8000/api/v1';
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        // Default ini untuk Android Emulator. HP fisik wajib pakai IP laptop.
        return 'http://10.0.2.2:8000/api/v1';
      case TargetPlatform.iOS:
      case TargetPlatform.macOS:
      case TargetPlatform.windows:
      case TargetPlatform.linux:
      default:
        return 'http://localhost:8000/api/v1';
    }
  }

  /// Injectable HTTP client — bisa diganti MockClient di test.
  static http.Client client = http.Client();

  /// Batas waktu default tiap request (detik).
  static const Duration requestTimeout = Duration(seconds: 30);

  /// Dipanggil saat token ditolak server agar state aplikasi segera dibersihkan.
  static Future<void> Function()? onUnauthorized;

  static const _tokenKey = 'auth_token';
  static const _secureStorage = FlutterSecureStorage();

  static bool get _usesSecureStorage =>
      kReleaseMode &&
      (kIsWeb ||
          defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS ||
          defaultTargetPlatform == TargetPlatform.macOS);

  static Future<String?> getToken() async {
    if (_usesSecureStorage) {
      try {
        return await _secureStorage
            .read(key: _tokenKey)
            .timeout(const Duration(seconds: 1));
      } catch (_) {
        return null;
      }
    }
    return (await SharedPreferences.getInstance()).getString(_tokenKey);
  }

  static Future<void> saveToken(String token) async {
    if (_usesSecureStorage) {
      await _secureStorage.write(key: _tokenKey, value: token);
    } else {
      await (await SharedPreferences.getInstance()).setString(_tokenKey, token);
    }
  }

  static Future<void> clearToken() async {
    if (_usesSecureStorage) {
      await _secureStorage.delete(key: _tokenKey);
    } else {
      await (await SharedPreferences.getInstance()).remove(_tokenKey);
    }
  }

  static Future<Map<String, String>> _headers() async {
    final token = await getToken();
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
  }

  static Future<dynamic> get(String endpoint) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client
        .get(uri, headers: await _headers())
        .timeout(requestTimeout);
    return _handleResponse(response);
  }

  static Future<dynamic> post(
    String endpoint, [
    Map<String, dynamic>? body,
  ]) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client
        .post(
          uri,
          headers: await _headers(),
          body: body != null ? jsonEncode(body) : null,
        )
        .timeout(requestTimeout);
    return _handleResponse(response);
  }

  static Future<dynamic> put(
    String endpoint, [
    Map<String, dynamic>? body,
  ]) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client
        .put(
          uri,
          headers: await _headers(),
          body: body != null ? jsonEncode(body) : null,
        )
        .timeout(requestTimeout);
    return _handleResponse(response);
  }

  static Future<dynamic> delete(
    String endpoint, [
    Map<String, dynamic>? body,
  ]) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client
        .delete(
          uri,
          headers: await _headers(),
          body: body != null ? jsonEncode(body) : null,
        )
        .timeout(requestTimeout);
    return _handleResponse(response);
  }

  static Future<Uint8List> getBytes(String endpoint) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client
        .get(uri, headers: await _headers())
        .timeout(requestTimeout);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return response.bodyBytes;
    }
    await _handleResponse(response);
    throw const ApiException(statusCode: 0, message: 'Unduhan gagal.');
  }

  /// Multipart upload — dipakai untuk upload file (import kasir, evidence, dll).
  /// Kirim [filePath] (mobile/desktop) ATAU [fileBytes]+[fileName] (web).
  /// [fieldName] nama field di backend (default 'file'); [fields] field tambahan (mis. period_id).
  static Future<dynamic> uploadFile(
    String endpoint, {
    String? filePath,
    Uint8List? fileBytes,
    String? fileName,
    String fieldName = 'file',
    Map<String, String>? fields,
  }) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final request = http.MultipartRequest('POST', uri);

    final token = await getToken();
    request.headers['Accept'] = 'application/json';
    if (token != null) request.headers['Authorization'] = 'Bearer $token';

    fields?.forEach((k, v) => request.fields[k] = v);

    if (filePath != null) {
      request.files.add(await http.MultipartFile.fromPath(fieldName, filePath));
    } else if (fileBytes != null && fileName != null) {
      request.files.add(
        http.MultipartFile.fromBytes(fieldName, fileBytes, filename: fileName),
      );
    } else {
      throw const ApiException(statusCode: 0, message: 'File belum dipilih.');
    }

    final streamed = await client.send(request).timeout(requestTimeout);
    final response = await http.Response.fromStream(streamed);
    return _handleResponse(response);
  }

  static Future<dynamic> _handleResponse(http.Response response) async {
    final body = _decodeBody(response.body);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return body;
    }

    if (response.statusCode == 401 ||
        (body is Map && body['code'] == 'SESSION_REVOKED')) {
      await onUnauthorized?.call();
    }

    final message = body is Map<String, dynamic>
        ? body['message']?.toString()
        : null;
    final errors = body is Map<String, dynamic> && body['errors'] is Map
        ? Map<String, dynamic>.from(body['errors'] as Map)
        : null;
    final code = body is Map<String, dynamic> ? body['code']?.toString() : null;

    throw ApiException(
      statusCode: response.statusCode,
      message: message?.trim().isNotEmpty == true
          ? message!
          : _statusMessage(response.statusCode),
      code: code,
      errors: errors,
    );
  }

  static dynamic _decodeBody(String rawBody) {
    if (rawBody.trim().isEmpty) return <String, dynamic>{};
    try {
      return jsonDecode(rawBody);
    } on FormatException {
      return null;
    }
  }

  static String _statusMessage(int statusCode) {
    switch (statusCode) {
      case 400:
        return 'Permintaan tidak valid.';
      case 401:
        return 'Sesi Anda telah berakhir. Silakan masuk kembali.';
      case 403:
        return 'Anda tidak memiliki akses untuk tindakan ini.';
      case 404:
        return 'Data tidak ditemukan.';
      case 409:
        return 'Data berubah di perangkat lain. Muat ulang lalu coba lagi.';
      case 422:
        return 'Periksa kembali data yang Anda masukkan.';
      case 429:
        return 'Terlalu banyak permintaan. Coba lagi sebentar.';
      default:
        return 'Terjadi kesalahan pada server ($statusCode).';
    }
  }
}
