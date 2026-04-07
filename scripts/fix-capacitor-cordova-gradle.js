import fs from 'node:fs';
import path from 'node:path';

const projectRoot = process.cwd();
const rootGradlePath = path.join(projectRoot, 'android', 'build.gradle');
const cordovaGradlePath = path.join(projectRoot, 'android', 'capacitor-cordova-android-plugins', 'build.gradle');

const rootGradle = fs.readFileSync(rootGradlePath, 'utf8');
const cordovaGradle = fs.readFileSync(cordovaGradlePath, 'utf8');

const rootAgpMatch = rootGradle.match(/com\.android\.tools\.build:gradle:(\d+\.\d+\.\d+)/);

if (!rootAgpMatch) {
  console.error('Could not determine Android Gradle Plugin version from android/build.gradle');
  process.exit(1);
}

const targetAgpVersion = rootAgpMatch[1];
const targetClasspath = `classpath 'com.android.tools.build:gradle:${targetAgpVersion}'`;
const nextCordovaGradle = cordovaGradle.replace(
  /classpath 'com\.android\.tools\.build:gradle:\d+\.\d+\.\d+'/,
  targetClasspath,
);

if (nextCordovaGradle === cordovaGradle) {
  console.log(`Capacitor Cordova Gradle already aligned to AGP ${targetAgpVersion}`);
  process.exit(0);
}

fs.writeFileSync(cordovaGradlePath, nextCordovaGradle);
console.log(`Aligned capacitor-cordova-android-plugins AGP to ${targetAgpVersion}`);
