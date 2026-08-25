import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiService {
  // Default URL: localhost for web/desktop, 10.0.2.2 for android emulator
  static String get baseUrl {
    if (kIsWeb) return 'http://localhost:8000/api/v1';
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
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

  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('auth_token');
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
    final response = await client.get(uri, headers: await _headers());
    return _handleResponse(response);
  }

  static Future<dynamic> post(String endpoint, [Map<String, dynamic>? body]) async {
    final uri = Uri.parse('$baseUrl$endpoint');
    final response = await client.post(
      uri,
      headers: await _headers(),
      body: body != null ? jsonEncode(body) : null,
    );
    return _handleResponse(response);
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
      request.files.add(http.MultipartFile.fromBytes(fieldName, fileBytes, filename: fileName));
    } else {
      throw Exception('filePath atau fileBytes+fileName wajib diisi.');
    }

    final streamed = await client.send(request);
    final response = await http.Response.fromStream(streamed);
    return _handleResponse(response);
  }

  static dynamic _handleResponse(http.Response response) {
    final body = jsonDecode(response.body);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return body;
    } else {
      final msg = body['message'] ?? 'Terjadi kesalahan pada server (${response.statusCode})';
      throw Exception(msg);
    }
  }
}
