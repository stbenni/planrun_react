package com.planrun.app

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Bundle

/**
 * Экран обоснования доступа Health Connect (обязателен по требованиям Google Play).
 * Открывает политику конфиденциальности и завершается.
 */
class HealthConnectRationaleActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        try {
            startActivity(
                Intent(Intent.ACTION_VIEW, Uri.parse(PRIVACY_URL))
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        } catch (_: Throwable) {
            // если нет браузера — просто закрываемся
        }
        finish()
    }

    companion object {
        private const val PRIVACY_URL = "https://planrun.ru/privacy"
    }
}
