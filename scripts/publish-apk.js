import { copyFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const rootDir = resolve(fileURLToPath(new URL('..', import.meta.url)))
const versionJsonPath = resolve(rootDir, 'public/version.json')
const releaseApkPath = resolve(rootDir, 'android/app/build/outputs/apk/release/app-release.apk')
const publicApkPath = resolve(rootDir, 'public/planrun.apk')
const testApkPath = resolve(rootDir, 'test.apk')
const downloadsDir = resolve(rootDir, 'downloads')

if (!existsSync(versionJsonPath)) {
  throw new Error('public/version.json is missing. Run scripts/bump-apk-version.js first.')
}

if (!existsSync(releaseApkPath)) {
  throw new Error(`Release APK is missing: ${releaseApkPath}`)
}

const versionInfo = JSON.parse(readFileSync(versionJsonPath, 'utf8'))
const versionName = versionInfo.version

if (!versionName) {
  throw new Error('public/version.json does not contain a version field.')
}

mkdirSync(downloadsDir, { recursive: true })

const archiveApkPath = resolve(downloadsDir, `planrun-${versionName}.apk`)

copyFileSync(releaseApkPath, testApkPath)
copyFileSync(releaseApkPath, publicApkPath)
copyFileSync(releaseApkPath, archiveApkPath)

console.log(`[apk] copied release APK to ${publicApkPath}`)
console.log(`[apk] archived release APK to ${archiveApkPath}`)
