package com.planrun.app;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Регистрируем нативные плагины ДО super.onCreate (требование Capacitor)
        registerPlugin(HealthConnectPlugin.class);
        registerPlugin(MediaSaverPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
