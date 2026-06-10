package com.planrun.app

import android.Manifest
import android.content.ContentValues
import android.media.MediaScannerConnection
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.util.Base64
import com.getcapacitor.JSObject
import com.getcapacitor.PermissionState
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin
import com.getcapacitor.annotation.Permission
import com.getcapacitor.annotation.PermissionCallback
import java.io.File
import java.io.FileOutputStream

@CapacitorPlugin(
    name = "MediaSaver",
    permissions = [
        Permission(alias = "storage", strings = [Manifest.permission.WRITE_EXTERNAL_STORAGE]),
    ],
)
class MediaSaverPlugin : Plugin() {

    @PluginMethod
    fun saveImage(call: PluginCall) {
        if (needsLegacyPermission() && getPermissionState("storage") != PermissionState.GRANTED) {
            requestPermissionForAlias("storage", call, "storagePermsCallback")
            return
        }
        performSave(call)
    }

    @PermissionCallback
    private fun storagePermsCallback(call: PluginCall) {
        if (getPermissionState("storage") != PermissionState.GRANTED) {
            call.reject("Storage permission denied")
            return
        }
        performSave(call)
    }

    private fun needsLegacyPermission(): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q

    private fun performSave(call: PluginCall) {
        val data = call.getString("data")
        val fileName = call.getString("fileName") ?: "planrun-${System.currentTimeMillis()}.jpg"
        if (data.isNullOrBlank()) {
            call.reject("No image data")
            return
        }
        try {
            val base64 = if (data.contains(",")) data.substringAfter(",") else data
            val bytes = Base64.decode(base64, Base64.DEFAULT)
            val mime = if (fileName.endsWith(".png", ignoreCase = true)) "image/png" else "image/jpeg"
            val uri = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                saveViaMediaStore(fileName, mime, bytes)
            } else {
                saveToLegacyGallery(fileName, mime, bytes)
            }
            val result = JSObject()
            result.put("saved", true)
            result.put("uri", uri)
            call.resolve(result)
        } catch (e: Exception) {
            call.reject("Save failed: ${e.message}", e)
        }
    }

    private fun saveViaMediaStore(fileName: String, mime: String, bytes: ByteArray): String {
        val resolver = context.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Images.Media.DISPLAY_NAME, fileName)
            put(MediaStore.Images.Media.MIME_TYPE, mime)
            put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/PlanRun")
            put(MediaStore.Images.Media.IS_PENDING, 1)
        }
        val uri = resolver.insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values)
            ?: throw IllegalStateException("MediaStore insert failed")
        resolver.openOutputStream(uri)?.use { it.write(bytes) }
            ?: throw IllegalStateException("openOutputStream returned null")
        values.clear()
        values.put(MediaStore.Images.Media.IS_PENDING, 0)
        resolver.update(uri, values, null, null)
        return uri.toString()
    }

    private fun saveToLegacyGallery(fileName: String, mime: String, bytes: ByteArray): String {
        val dir = File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_PICTURES), "PlanRun")
        if (!dir.exists()) dir.mkdirs()
        val file = File(dir, fileName)
        FileOutputStream(file).use { it.write(bytes) }
        MediaScannerConnection.scanFile(context, arrayOf(file.absolutePath), arrayOf(mime), null)
        return file.absolutePath
    }
}
