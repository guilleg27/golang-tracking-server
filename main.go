package main

import (
	"crypto/md5"
	"encoding/hex"
	"fmt"
	"log"
	"net/http"
	"reflect"
	"regexp"
	"strconv"
	"strings"
	"time"

	"./models"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gorilla/mux"
	"github.com/ip2location/ip2location-go"
	ua "github.com/mileusna/useragent"
)

var (
	advertiserId int
	ntoken, pubId, source, deviceId, gaid, idfa	string
)

var macros = make(map[string]interface{})

type Campaign struct {
	Id, ProvidersId, OpportunitiesId int
	Url, Status	string
}

type Opportunity struct {
	Id, DealsId	int
	ServerToServer, PlaceholderPublisherId, PlaceholderSource, PlaceholderDeviceId, PlaceholderGaid, PlaceholderIdfa, PlaceholderProviderId string
}

type Deal struct {
	Id, AdvertiserId int
}

type Provider struct {
	Id     int
	HasS2S bool
}

type ClicksLog struct {
	Id int64
	CampaignsId, ProvidersId int
	PublisherId, Source, DeviceId, Gaid, Idfa, Tid, TidInternal, ServerIp, UserAgent, Languaje, Referer, IpForwarded, Country, City, Carrier, Browser, BrowserVersion, Device, DeviceModel, Os, OsVersion, App, RedirectURL, CustomParams	string
}

func main() {
	r := mux.NewRouter()
	r.HandleFunc("/tracking/{id:[0-9]+}/", Tracking).Methods("GET")
	log.Fatal(http.ListenAndServe(":3000", r))
}

func Tracking(w http.ResponseWriter, r *http.Request) {
	models.CreateConnection()
	muxParams   := mux.Vars(r)
	id        	:= muxParams["id"]
	cid, err := strconv.Atoi(id)
	if err != nil {
		panic(err)
	}
	campaign := getCampaign(cid)
	// fmt.Println(campaign)
	if (campaign.Id == 0){
        errorHandler(w, r, http.StatusNotFound)
        return
	}
	opportunity := getOpportunity(campaign.OpportunitiesId)
	// fmt.Printf("%v\n", opportunity)
	deal        := getDeal(opportunity.DealsId)
	provider    := getProvider(campaign.ProvidersId)
	advertiserId = deal.AdvertiserId
	
	captureGetParams(r)
	click := ClicksLog{}
	click.CampaignsId = cid
	click.ProvidersId = campaign.ProvidersId
	click.PublisherId = pubId
	click.setCustomParams(provider, r)
	click.setVisitorParams(r)
	click.setIpValues()
	click.setDeviceData()
	click.setSource(deal, opportunity)
	click.save()
	// fmt.Printf("\n%+v\n", click)
	click.setTokens()
	// fmt.Printf("\n%+v\n", click)
	click.setMacros()

	urlRedirect := click.generateURLRedirect(campaign, opportunity)
	models.CloseConnection()
	fmt.Println(urlRedirect)
	fmt.Fprint(w, urlRedirect)
	// http.Redirect(w, r, urlRedirect, http.StatusMovedPermanently)
}

func errorHandler(w http.ResponseWriter, r *http.Request, status int) {
    w.WriteHeader(status)
    if status == http.StatusNotFound {
        fmt.Fprint(w, "404. Resource not found.")
    }
}

func captureGetParams(r *http.Request){
	queryParams := r.URL.Query()
	ntoken   = queryParams.Get("ntoken")
	pubId    = queryParams.Get("pub_id")
	source   = queryParams.Get("source")
	deviceId = queryParams.Get("device_id")
	gaid     = queryParams.Get("gaid")
	idfa     = queryParams.Get("idfa")
}

func addCharacterToUrl(url string) string{
	urlContainsQuery := strings.Contains(url, "?")
	if urlContainsQuery{
		return "&"
	}else{
		return "?"
	}	
}

func getCampaign(cid int) *Campaign {
	var campaign Campaign
	results, err := models.Query("SELECT id, opportunities_id, providers_id, COALESCE(url, '') as url, COALESCE(status, '') as status FROM campaigns WHERE id=?", cid)
	if err != nil {
		log.Println(err)
	}
	for results.Next() {
		err := results.Scan(&campaign.Id, &campaign.OpportunitiesId, &campaign.ProvidersId, &campaign.Url, &campaign.Status)
		if err != nil {
            panic(err.Error())
        }
	}
	return &campaign
}

func getOpportunity(oppId int) *Opportunity {
	var opportunity Opportunity
	results, _ := models.Query("SELECT id, deals_id, COALESCE(server_to_server, '') as server_to_server, COALESCE(placeholder_publisher, '') as placeholder_publisher, COALESCE(placeholder_source, '') as placeholder_source, COALESCE(placeholder_provider_id, '') as placeholder_provider_id, COALESCE(placeholder_device_id, '') as placeholder_device_id, COALESCE(placeholder_gaid, '') as placeholder_gaid, COALESCE(placeholder_idfa, '') as placeholder_idfa FROM opportunities WHERE id=?", oppId)
	for results.Next() {
		results.Scan(&opportunity.Id, &opportunity.DealsId, &opportunity.ServerToServer, &opportunity.PlaceholderPublisherId, &opportunity.PlaceholderSource, &opportunity.PlaceholderProviderId, &opportunity.PlaceholderDeviceId, &opportunity.PlaceholderGaid, &opportunity.PlaceholderIdfa)
	}
	return &opportunity
}

func getDeal(dealId int) *Deal {
	var deal Deal
	results, _ := models.Query("SELECT id, advertisers_id FROM deals WHERE id=?", dealId)
	for results.Next() {
		results.Scan(&deal.Id, &deal.AdvertiserId)
	}
	return &deal
}

func getProvider(provId int) *Provider {
	var provider Provider
	results, _ := models.Query("SELECT id, has_s2s FROM providers WHERE id=?", provId)
	for results.Next() {
		results.Scan(&provider.Id, &provider.HasS2S)
	}
	return &provider
}

func existsInBlacklist(source string) bool{
	rows, _ := models.Query("SELECT source FROM blacklist where source=?", source)	
	if rows.Next() {
		return true
	} else {
		return false
	}
}

func inArray(val string, array []string) bool{
	exists := false
	for _, v := range array {
		if val == v {
			exists = true
			return exists
		}
	}
	return exists
}

func getMD5Hash(id int64) string {
	token := strconv.FormatInt(int64(id), 10)
	hash := md5.Sum([]byte(token))
	return hex.EncodeToString(hash[:])
 }

func (this *ClicksLog) setCustomParams(provider *Provider, r *http.Request){
	queryParams := r.URL.Query()
	if provider.HasS2S {
		ignoreParams := []string{"g_net", "g_key", "g_cre", "g_pla", "g_mty", "g_dev", "b_key", "g_cre", "b_mty", "b_dev", "b_q", "ntoken", "id", "pub_id", "source", "device_id", "gaid", "idfa"}
		for k, v := range queryParams {
			if exists := inArray(k, ignoreParams); exists == false {
				if len(this.CustomParams) == 0 {
					this.CustomParams = ""
				}else{
					this.CustomParams += "&"
				}
				this.CustomParams += k + "=" + strings.Join(v, "")
			}
		}
	}
}

func (this *ClicksLog) setVisitorParams(r *http.Request){
	this.ServerIp    = r.RemoteAddr
	this.IpForwarded = r.Header.Get("X-FORWARDED-FOR")
	this.UserAgent   = r.UserAgent()
	this.Languaje    = r.Header.Get("Accept-Language")
	this.Referer     = r.Header.Get("Referer")
	// this.App = r.Header.Get("??")
	this.RedirectURL = r.URL.RequestURI()
}

func (this *ClicksLog) setIpValues(){
	var ip string
    ipdb, err := ip2location.OpenDB("./IP2LOCATION-LITE-DB1.BIN")
    if err != nil {
		panic(err)
        return
	}
	
	if this.ServerIp != ""{
		ip = this.ServerIp
	}else{
		ip = this.IpForwarded
	}
    results, err := ipdb.Get_all(ip)
    if err != nil {
        fmt.Print(err)
        return
    }
     
    // fmt.Printf("country_short: %s\n", results.Country_short)
    // fmt.Printf("region: %s\n", results.Region)
    // fmt.Printf("city: %s\n", results.City)
    // fmt.Printf("mobilebrand: %s\n", results.Mobilebrand)
	this.Country = results.Country_short
	this.City = results.City
    ipdb.Close()
}

func (this *ClicksLog) setDeviceData(){
	ua := ua.Parse(this.UserAgent)
	// fmt.Println(ua.String)
	// fmt.Println(strings.Repeat("=", len(ua.String)))
	// fmt.Println("Name:", ua.Name, "v", ua.Version)
	// fmt.Println("OS:", ua.OS, "v", ua.OSVersion)
	// fmt.Println("Device:", ua.Device)
	this.Os = ua.OS
	this.OsVersion = ua.OSVersion
	this.Browser = ua.Name
	this.BrowserVersion = ua.Version
}

func (this *ClicksLog) setSource(deal *Deal, opp *Opportunity){
	switch deal.AdvertiserId {
		case 376:
			if opp.Id == 3042{
				//Buscar source en tabla autocomplete
				rows, _ := models.Query("SELECT source FROM autocomplete order by RAND() limit 1")	
				for rows.Next() {
					rows.Scan(&this.Source)
				}
			}else if this.ProvidersId == 1095 {
				isInvalidSource := this.isInvalidSource(source)
				if isInvalidSource == false{
					this.Source = source
				}else{
					rows, _ := models.Query("SELECT source FROM source_list WHERE os = 'Android' order by RAND() limit 1")	
					for rows.Next() {
						rows.Scan(&this.Source)
					}
				}
			}else{
				if this.Os == "Android"{
					rows, _ := models.Query("SELECT source FROM source_list WHERE os = 'Android' order by RAND() limit 1")	
					for rows.Next() {
						rows.Scan(&this.Source)
					}
				}else{
					rows, _ := models.Query("SELECT source FROM source_list WHERE os = 'iOS' order by RAND() limit 1")	
					for rows.Next() {
						rows.Scan(&this.Source)
					}
				}
			}

		default:
			this.Source = source
	}
}

func (this *ClicksLog) isInvalidSource(source string) bool{
	// fmt.Printf("source: %+v\n",source)
	invalid := false

	// Check si source es menor o igual 3 caracteres
	if len(source) <= 3{
		return true
	}

	// Check si source es mayor o igual a 30 caracteres
	if len(source) >= 30{
		return true
	}
	
	//Check solo aceptar caracteres alfanumericos
	reCharacters := regexp.MustCompile("^[a-zA-Z0-9_]*$")
	if reCharacters.MatchString(source) == false{
		return true
	}
	
	
	//Check si tiene más de 3 números seguidos
	reNumbers := regexp.MustCompile("^[0-9]{3}")
	if (reNumbers.MatchString(source) == true){
		return true
	}
	
	
	// Check si tiene más de 4 consonantes consecutivas
	reCons := regexp.MustCompile("([b-df-hj-np-tv-z]){4,}")
	if (reCons.MatchString(source) == true){
		return true
	}

	//Check si existe en BlackList
	return existsInBlacklist(source)

	return invalid
}

func (this *ClicksLog) setMacros(){
	macros["{click_id}"]     = this.Id
	macros["{campaign_id}"]  = this.CampaignsId
	macros["{tid}"]          = this.Tid
	macros["{gaid}"]         = this.Gaid
	macros["{idfa}"]         = this.Idfa
	macros["{ktoken}"]       = this.TidInternal
	macros["{provider_id}"]  = this.ProvidersId
	macros["{publisher_id}"] = this.PublisherId
	macros["{random}"]       = time.Now().UnixNano()
	macros["{source}"]       = this.getSource()
}

func haveMacro(url string) bool{
	reMacro := regexp.MustCompile("{[a-z]+}")
	return reMacro.MatchString(url)
}

func replaceMacro(url string) string{	
	for key, value := range macros {
		url = strings.Replace(url, key, fmt.Sprint(value), -1)
	}
	return url
}

func (this *ClicksLog) setTokens(){
	ktoken := getMD5Hash(this.Id)
	this.Tid         = ntoken
	this.TidInternal = ktoken
	this.saveTokens()
}

func (this *ClicksLog) save(){
	sql := "INSERT INTO clicks_log_today(browser, browser_version, campaigns_id, carrier, city, country, custom_params, device, device_model, gaid, idfa, ip_forwarded, languaje, os, os_version, providers_id, publisher_id, referer, server_ip, source, user_agent, redirect_url) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
	result, _ := models.Exec(sql, this.Browser, this.BrowserVersion, this.CampaignsId, this.Carrier, this.City, this.Country, this.CustomParams, this.Device, this.DeviceModel, this.Gaid, this.Idfa, this.IpForwarded, this.Languaje, this.Os, this.OsVersion, this.ProvidersId, this.PublisherId, this.Referer, this.ServerIp, this.Source, this.UserAgent, this.RedirectURL)
	this.Id, _ = result.LastInsertId()
}

func (this *ClicksLog) saveTokens(){
	sql := "UPDATE clicks_log_today SET tid=?, tid_internal=? WHERE id = ?"
	models.Exec(sql, this.Tid, this.TidInternal, this.Id)
}

func (this *ClicksLog) getSource() string{
	if advertiserId == 376{
		return strconv.Itoa(this.ProvidersId) + "_" + this.Source
	}else{
		return this.Source
	}
}

func (this *ClicksLog) generateURLRedirect(c *Campaign, opp *Opportunity) string{
	url     := c.Url
	if haveMacro(url){
		url = replaceMacro(url)
	}
	s := reflect.ValueOf(opp).Elem()
	typeOfT := s.Type()
	for i := 0; i < s.NumField(); i++ {
			f := s.Field(i)
			if typeOfT.Field(i).Name == "ServerToServer" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				url += fmt.Sprint(f.Interface()) + "=" + this.TidInternal
			}
			if typeOfT.Field(i).Name == "PlaceholderSource" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				url += fmt.Sprint(f.Interface()) + "=" + this.getSource()
			}
			if typeOfT.Field(i).Name == "PlaceholderPublisherId" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				url += fmt.Sprint(f.Interface()) + "=" + this.PublisherId
			}
			if typeOfT.Field(i).Name == "PlaceholderProviderId" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				provId := strconv.Itoa(this.ProvidersId)
				url += fmt.Sprint(f.Interface()) + "=" + provId
			}
			if typeOfT.Field(i).Name == "PlaceholderGaid" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				url += fmt.Sprint(f.Interface()) + "=" + this.Gaid
			}
			if typeOfT.Field(i).Name == "PlaceholderIdfa" && f.Interface() != ""{
				url += addCharacterToUrl(url)
				url += fmt.Sprint(f.Interface()) + "=" + this.Idfa
			}
	}
	return url
} 