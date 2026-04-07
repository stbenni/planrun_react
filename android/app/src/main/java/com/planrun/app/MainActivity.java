package com.planrun.app;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.text.Editable;
import android.text.InputFilter;
import android.text.InputType;
import android.text.TextWatcher;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import android.widget.FrameLayout;
import androidx.appcompat.widget.AppCompatEditText;
import androidx.appcompat.widget.AppCompatImageButton;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import com.getcapacitor.BridgeActivity;
import java.util.Locale;
import org.json.JSONException;
import org.json.JSONObject;

public class MainActivity extends BridgeActivity {
    private static final String NATIVE_CHAT_BRIDGE_NAME = "PlanRunNativeChatComposer";

    private int safeAreaTopCssPx = 0;
    private int safeAreaRightCssPx = 0;
    private int safeAreaBottomCssPx = 0;
    private int safeAreaLeftCssPx = 0;
    private int imeBottomCssPx = 0;
    private int safeAreaBottomPx = 0;
    private int imeBottomPx = 0;
    private boolean imeVisible = false;

    private FrameLayout nativeChatComposerHost = null;
    private AppCompatEditText nativeChatInput = null;
    private AppCompatImageButton nativeChatActionButton = null;
    private boolean nativeChatComposerVisible = false;
    private boolean nativeChatComposerDisabled = false;
    private boolean nativeChatComposerSubmitting = false;
    private boolean nativeChatComposerStopMode = false;
    private boolean suppressNativeChatTextWatcher = false;
    private int nativeChatActionButtonTint = Color.WHITE;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        hardenWebViewTextAssist();
        installNativeInsetsBridge();
        installNativeChatComposerBridge();
    }

    private void hardenWebViewTextAssist() {
        if (getBridge() == null || getBridge().getWebView() == null) {
            return;
        }

        View webView = getBridge().getWebView();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            webView.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS);
            webView.setAutofillHints((String[]) null);
        }
    }

    private void installNativeInsetsBridge() {
        if (getBridge() == null || getBridge().getWebView() == null) {
            return;
        }

        View webView = getBridge().getWebView();
        View hostView = webView.getParent() instanceof View ? (View) webView.getParent() : webView;

        ViewCompat.setOnApplyWindowInsetsListener(hostView, (view, insets) -> {
            Insets safeAreaInsets = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            Insets imeInsets = insets.getInsets(WindowInsetsCompat.Type.ime());
            imeVisible = insets.isVisible(WindowInsetsCompat.Type.ime());
            safeAreaTopCssPx = pxToCssPx(safeAreaInsets.top);
            safeAreaRightCssPx = pxToCssPx(safeAreaInsets.right);
            safeAreaBottomCssPx = pxToCssPx(safeAreaInsets.bottom);
            safeAreaLeftCssPx = pxToCssPx(safeAreaInsets.left);
            imeBottomCssPx = pxToCssPx(imeVisible ? imeInsets.bottom : 0);
            safeAreaBottomPx = safeAreaInsets.bottom;
            imeBottomPx = imeVisible ? imeInsets.bottom : 0;
            injectNativeInsetsCSS(webView);
            updateNativeChatComposerInsets();

            return insets;
        });

        webView.addOnLayoutChangeListener((view, left, top, right, bottom, oldLeft, oldTop, oldRight, oldBottom) -> {
            if ((bottom - top) != (oldBottom - oldTop)) {
                injectNativeInsetsCSS(webView);
            }
        });

        hostView.requestApplyInsets();
    }

    private void installNativeChatComposerBridge() {
        if (getBridge() == null || getBridge().getWebView() == null) {
            return;
        }

        WebView webView = getBridge().getWebView();
        webView.addJavascriptInterface(new NativeChatComposerBridge(), NATIVE_CHAT_BRIDGE_NAME);

        View rootView = findViewById(android.R.id.content);
        if (!(rootView instanceof ViewGroup rootContainer)) {
            return;
        }

        nativeChatComposerHost = new FrameLayout(this);

        FrameLayout.LayoutParams hostParams = new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
            Gravity.BOTTOM
        );
        nativeChatComposerHost.setLayoutParams(hostParams);
        nativeChatComposerHost.setPadding(dpToPx(8), dpToPx(6), dpToPx(8), dpToPx(6));
        nativeChatComposerHost.setClipToPadding(false);
        nativeChatComposerHost.setVisibility(View.GONE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            nativeChatComposerHost.setElevation(dpToPx(18));
            nativeChatComposerHost.setTranslationZ(dpToPx(18));
        }

        View composerRow = buildNativeChatComposerRow();
        nativeChatComposerHost.addView(composerRow);
        rootContainer.addView(nativeChatComposerHost);
        updateNativeChatComposerInsets();
        applyNativeChatComposerTheme("light", null);
        updateNativeChatActionButtonState();
    }

    private View buildNativeChatComposerRow() {
        android.widget.LinearLayout row = new android.widget.LinearLayout(this);
        row.setOrientation(android.widget.LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setBackgroundColor(Color.TRANSPARENT);

        nativeChatInput = new AppCompatEditText(this);
        android.widget.LinearLayout.LayoutParams inputParams = new android.widget.LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        inputParams.setMarginEnd(dpToPx(8));
        nativeChatInput.setLayoutParams(inputParams);
        nativeChatInput.setMinHeight(dpToPx(48));
        nativeChatInput.setMaxLines(4);
        nativeChatInput.setGravity(Gravity.START | Gravity.CENTER_VERTICAL);
        nativeChatInput.setPadding(dpToPx(18), dpToPx(13), dpToPx(18), dpToPx(13));
        nativeChatInput.setTextSize(TypedValue.COMPLEX_UNIT_SP, 17);
        nativeChatInput.setIncludeFontPadding(false);
        nativeChatInput.setImeOptions(EditorInfo.IME_ACTION_SEND);
        nativeChatInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        nativeChatInput.setFilters(new InputFilter[] { new InputFilter.LengthFilter(4000) });
        nativeChatInput.setHorizontallyScrolling(false);
        nativeChatInput.setVerticalScrollBarEnabled(false);
        nativeChatInput.setOverScrollMode(View.OVER_SCROLL_NEVER);
        nativeChatInput.setBackgroundTintList(null);
        nativeChatInput.addTextChangedListener(new TextWatcher() {
            @Override
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {
            }

            @Override
            public void onTextChanged(CharSequence s, int start, int before, int count) {
            }

            @Override
            public void afterTextChanged(Editable editable) {
                if (!suppressNativeChatTextWatcher) {
                    updateNativeChatActionButtonState();
                }
            }
        });
        nativeChatInput.setOnEditorActionListener((textView, actionId, event) -> {
            boolean isHardwareEnter = event != null
                && event.getAction() == KeyEvent.ACTION_DOWN
                && event.getKeyCode() == KeyEvent.KEYCODE_ENTER
                && !event.isShiftPressed();
            boolean isImeSend = actionId == EditorInfo.IME_ACTION_SEND || actionId == EditorInfo.IME_ACTION_DONE;
            if (!isHardwareEnter && !isImeSend) {
                return false;
            }

            if (nativeChatComposerStopMode) {
                dispatchNativeChatStop();
            } else {
                dispatchNativeChatSubmit();
            }
            return true;
        });
        row.addView(nativeChatInput);

        nativeChatActionButton = new AppCompatImageButton(this);
        android.widget.LinearLayout.LayoutParams buttonParams = new android.widget.LinearLayout.LayoutParams(dpToPx(48), dpToPx(48));
        nativeChatActionButton.setLayoutParams(buttonParams);
        nativeChatActionButton.setPadding(0, 0, 0, 0);
        nativeChatActionButton.setBackgroundTintList(null);
        nativeChatActionButton.setScaleType(android.widget.ImageView.ScaleType.CENTER);
        nativeChatActionButton.setAdjustViewBounds(false);
        nativeChatActionButton.setOnClickListener((view) -> {
            if (nativeChatComposerStopMode) {
                dispatchNativeChatStop();
            } else {
                dispatchNativeChatSubmit();
            }
        });
        row.addView(nativeChatActionButton);

        return row;
    }

    private void setNativeChatComposerConfig(String configJson) {
        runOnUiThread(() -> {
            try {
                JSONObject config = new JSONObject(configJson == null ? "{}" : configJson);
                nativeChatComposerVisible = config.optBoolean("visible", true);
                nativeChatComposerDisabled = config.optBoolean("disabled", false);
                nativeChatComposerSubmitting = config.optBoolean("submitting", false);
                nativeChatComposerStopMode = config.optBoolean("showStopButton", false);
                String placeholder = config.optString("placeholder", "");
                String theme = config.optString("theme", "light");
                JSONObject styles = config.optJSONObject("styles");

                if (nativeChatInput != null) {
                    nativeChatInput.setHint(placeholder);
                    nativeChatInput.setEnabled(!nativeChatComposerDisabled);
                }

                applyNativeChatComposerTheme(theme, styles);
                updateNativeChatComposerVisibility();
                updateNativeChatActionButtonState();
            } catch (JSONException ignored) {
            }
        });
    }

    private void updateNativeChatComposerVisibility() {
        if (nativeChatComposerHost == null) {
            return;
        }

        nativeChatComposerHost.setVisibility(nativeChatComposerVisible ? View.VISIBLE : View.GONE);
        if (nativeChatComposerVisible) {
            nativeChatComposerHost.bringToFront();
        } else {
            hideNativeKeyboard();
            if (nativeChatInput != null) {
                nativeChatInput.clearFocus();
            }
        }
    }

    private void updateNativeChatComposerInsets() {
        if (nativeChatComposerHost == null) {
            return;
        }

        FrameLayout.LayoutParams params = (FrameLayout.LayoutParams) nativeChatComposerHost.getLayoutParams();
        int bottomInset = imeVisible ? Math.max(imeBottomPx, safeAreaBottomPx) : safeAreaBottomPx;
        params.bottomMargin = Math.max(0, bottomInset);
        nativeChatComposerHost.setLayoutParams(params);
    }

    private void updateNativeChatActionButtonState() {
        if (nativeChatInput == null || nativeChatActionButton == null) {
            return;
        }

        String content = nativeChatInput.getText() == null ? "" : nativeChatInput.getText().toString();
        boolean hasText = content.trim().length() > 0;
        boolean enabled = !nativeChatComposerDisabled && (nativeChatComposerStopMode || (!nativeChatComposerSubmitting && hasText));

        nativeChatActionButton.setEnabled(enabled);
        nativeChatActionButton.setAlpha(enabled ? 1f : 0.5f);
        int iconRes = nativeChatComposerStopMode ? R.drawable.ic_chat_stop : R.drawable.ic_chat_send;
        nativeChatActionButton.setImageDrawable(ContextCompat.getDrawable(this, iconRes));
        nativeChatActionButton.setImageTintList(ColorStateList.valueOf(nativeChatActionButtonTint));
        nativeChatActionButton.setContentDescription(nativeChatComposerStopMode ? "Остановить" : "Отправить");
    }

    private int parseColorOrDefault(String colorValue, int fallbackColor) {
        if (colorValue == null) {
            return fallbackColor;
        }

        String normalizedValue = colorValue.trim();
        if (normalizedValue.isEmpty()) {
            return fallbackColor;
        }

        try {
            return Color.parseColor(normalizedValue);
        } catch (IllegalArgumentException ignored) {
            return fallbackColor;
        }
    }

    private int[] resolveGradient(JSONObject styles, String startKey, String endKey, int startFallback, int endFallback) {
        String startValue = styles == null ? null : styles.optString(startKey, null);
        String endValue = styles == null ? null : styles.optString(endKey, null);

        return new int[] {
            parseColorOrDefault(startValue, startFallback),
            parseColorOrDefault(endValue, endFallback)
        };
    }

    private int resolveColor(JSONObject styles, String key, int fallbackColor) {
        return parseColorOrDefault(styles == null ? null : styles.optString(key, null), fallbackColor);
    }

    private void applyNativeChatComposerTheme(String theme, JSONObject styles) {
        if (nativeChatComposerHost == null || nativeChatInput == null || nativeChatActionButton == null) {
            return;
        }

        boolean isDark = "dark".equalsIgnoreCase(theme);
        int[] hostGradient = resolveGradient(
            styles,
            "shellTop",
            "shellBottom",
            Color.parseColor(isDark ? "#121821" : "#FAFCFF"),
            Color.parseColor(isDark ? "#0B1016" : "#F3F7FB")
        );
        int hostBorder = resolveColor(styles, "shellBorder", Color.parseColor(isDark ? "#2A313A" : "#E2E8F0"));
        int[] inputGradient = resolveGradient(
            styles,
            "inputTop",
            "inputBottom",
            Color.parseColor(isDark ? "#161B21" : "#FFFFFF"),
            Color.parseColor(isDark ? "#0F1318" : "#F7FAFC")
        );
        int inputBorder = resolveColor(styles, "inputBorder", Color.parseColor("#FC4C02"));
        int inputText = resolveColor(styles, "inputText", Color.parseColor(isDark ? "#F3F4F6" : "#0F172A"));
        int inputHint = resolveColor(styles, "inputPlaceholder", Color.parseColor(isDark ? "#94A3B8" : "#64748B"));
        int[] buttonGradient = resolveGradient(
            styles,
            "buttonTop",
            "buttonBottom",
            Color.parseColor("#FC4C02"),
            Color.parseColor("#E03D00")
        );
        int buttonText = resolveColor(styles, "buttonText", Color.WHITE);

        GradientDrawable hostShape = new GradientDrawable();
        hostShape.setOrientation(GradientDrawable.Orientation.TOP_BOTTOM);
        hostShape.setColors(hostGradient);
        hostShape.setCornerRadius(dpToPx(24));
        hostShape.setStroke(dpToPx(1), hostBorder);
        nativeChatComposerHost.setBackground(hostShape);

        GradientDrawable inputShape = new GradientDrawable();
        inputShape.setOrientation(GradientDrawable.Orientation.TOP_BOTTOM);
        inputShape.setColors(inputGradient);
        inputShape.setCornerRadius(dpToPx(18));
        inputShape.setStroke(dpToPx(1), inputBorder);
        nativeChatInput.setBackground(inputShape);
        nativeChatInput.setBackgroundTintList(ColorStateList.valueOf(Color.TRANSPARENT));
        nativeChatInput.setTextColor(inputText);
        nativeChatInput.setHintTextColor(inputHint);
        nativeChatInput.setHighlightColor(Color.parseColor("#33FC4C02"));

        GradientDrawable buttonShape = new GradientDrawable();
        buttonShape.setOrientation(GradientDrawable.Orientation.TL_BR);
        buttonShape.setColors(buttonGradient);
        buttonShape.setCornerRadius(dpToPx(18));
        nativeChatActionButton.setBackground(buttonShape);
        nativeChatActionButton.setBackgroundTintList(ColorStateList.valueOf(Color.TRANSPARENT));
        nativeChatActionButtonTint = buttonText;
        nativeChatActionButton.setImageTintList(ColorStateList.valueOf(buttonText));
    }

    private void setNativeChatComposerText(String text, boolean moveCaretToEnd) {
        runOnUiThread(() -> {
            if (nativeChatInput == null) {
                return;
            }

            String nextValue = text == null ? "" : text;
            suppressNativeChatTextWatcher = true;
            nativeChatInput.setText(nextValue);
            int selection = moveCaretToEnd ? nextValue.length() : Math.min(nativeChatInput.length(), nextValue.length());
            nativeChatInput.setSelection(selection);
            suppressNativeChatTextWatcher = false;
            updateNativeChatActionButtonState();
        });
    }

    private void clearNativeChatComposer() {
        setNativeChatComposerText("", true);
    }

    private void focusNativeChatComposer() {
        runOnUiThread(() -> {
            if (nativeChatInput == null) {
                return;
            }

            nativeChatComposerVisible = true;
            updateNativeChatComposerVisibility();
            nativeChatInput.requestFocus();
            InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            if (imm != null) {
                imm.showSoftInput(nativeChatInput, InputMethodManager.SHOW_IMPLICIT);
            }
        });
    }

    private void hideNativeChatComposer() {
        runOnUiThread(() -> {
            nativeChatComposerVisible = false;
            updateNativeChatComposerVisibility();
        });
    }

    private void hideNativeKeyboard() {
        if (nativeChatInput == null) {
            return;
        }

        InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) {
            imm.hideSoftInputFromWindow(nativeChatInput.getWindowToken(), 0);
        }
    }

    private void dispatchNativeChatSubmit() {
        if (nativeChatInput == null || nativeChatComposerDisabled) {
            return;
        }

        String content = nativeChatInput.getText() == null ? "" : nativeChatInput.getText().toString();
        if (content.trim().isEmpty()) {
            return;
        }

        try {
            JSONObject detail = new JSONObject();
            detail.put("text", content);
            dispatchNativeChatEvent("planrun:native-chat-submit", detail);
        } catch (JSONException ignored) {
        }
    }

    private void dispatchNativeChatStop() {
        dispatchNativeChatEvent("planrun:native-chat-stop", null);
    }

    private void dispatchNativeChatEvent(String eventName, JSONObject detail) {
        if (getBridge() == null || getBridge().getWebView() == null) {
            return;
        }

        String eventNameJson = JSONObject.quote(eventName);
        String detailJson = detail == null ? "null" : detail.toString();

        String script = String.format(
            Locale.US,
            """
            try {
              window.dispatchEvent(new CustomEvent(%s, { detail: %s }));
            } catch (error) {
              console.error('planRUN native chat composer bridge failed', error);
            }
            """,
            eventNameJson,
            detailJson
        );

        getBridge().getWebView().post(() -> getBridge().getWebView().evaluateJavascript(script, null));
    }

    private int pxToCssPx(int px) {
        float density = getResources().getDisplayMetrics().density;
        return Math.max(0, Math.round(px / density));
    }

    private int dpToPx(int dp) {
        float density = getResources().getDisplayMetrics().density;
        return Math.round(dp * density);
    }

    private void injectNativeInsetsCSS(View webView) {
        if (getBridge() == null || getBridge().getWebView() == null) {
            return;
        }

        int viewportHeight = pxToCssPx(Math.max(0, webView.getHeight()));

        String script = String.format(
            Locale.US,
            """
            try {
              const detail = {
                safeAreaTop: %d,
                safeAreaRight: %d,
                safeAreaBottom: %d,
                safeAreaLeft: %d,
                imeBottom: %d,
                imeVisible: %s,
                viewportHeight: %d
              };
              const root = document.documentElement;
              root.style.setProperty('--native-safe-area-top', detail.safeAreaTop + 'px');
              root.style.setProperty('--native-safe-area-right', detail.safeAreaRight + 'px');
              root.style.setProperty('--native-safe-area-bottom', detail.safeAreaBottom + 'px');
              root.style.setProperty('--native-safe-area-left', detail.safeAreaLeft + 'px');
              root.style.setProperty('--native-ime-inset-bottom', detail.imeBottom + 'px');
              root.style.setProperty('--native-layout-viewport-height', detail.viewportHeight + 'px');
              window.dispatchEvent(new CustomEvent('planrun:native-insets', { detail }));
            } catch (error) {
              console.error('planRUN native inset bridge failed', error);
            }
            """,
            safeAreaTopCssPx,
            safeAreaRightCssPx,
            safeAreaBottomCssPx,
            safeAreaLeftCssPx,
            imeBottomCssPx,
            imeVisible ? "true" : "false",
            viewportHeight
        );

        getBridge().getWebView().post(() -> getBridge().getWebView().evaluateJavascript(script, null));
    }

    private final class NativeChatComposerBridge {
        @JavascriptInterface
        public void setComposerConfig(String configJson) {
            setNativeChatComposerConfig(configJson);
        }

        @JavascriptInterface
        public void hideComposer() {
            hideNativeChatComposer();
        }

        @JavascriptInterface
        public void focusComposer() {
            focusNativeChatComposer();
        }

        @JavascriptInterface
        public void setComposerText(String text, boolean moveCaretToEnd) {
            setNativeChatComposerText(text, moveCaretToEnd);
        }

        @JavascriptInterface
        public void clearComposer() {
            clearNativeChatComposer();
        }
    }
}
