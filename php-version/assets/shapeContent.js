const o = (id, label, description) => ({ id, label, ...(description ? { description } : {}) });
const list = (values) => values.map((label) => o(label.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""), label));
export const tableOfContents = [
    ["What is S.H.A.P.E.?", "3"], ["Spiritual Gifts", "4"], ["Unwrapping My Gifts", "5-7"],
    ["Heart", "8"], ["Monitoring My Heartbeat", "9-10"], ["Abilities", "11"],
    ["Applying My Abilities", "12-13"], ["Personality", "14"], ["Plugging In My Personality", "15"],
    ["Experiences", "16"], ["Examining My Experiences", "17-20"], ["Determining My Availability", "21"],
    ["5 Ways to S.T.A.R.T. to Deepen Your S.H.A.P.E.", "23"],
];
export const welcomeSection = {
    id: "welcome", title: "SERVE – S.H.A.P.E. Discovery Tool", shortTitle: "Welcome", page: 1, eyebrow: "Discovering my S.H.A.P.E. for ministry",
    paragraphs: [
        "We are excited that you have taken the time to discover more about how God has SHAPED you for ministry. We desire that you find a place of service at Fellowship Dubai that fits the unique way God has created and designed you. Your SHAPE - Spiritual Gifts, Heart, Abilities, Personality, and Experience - influences your ministry, and our prayer is that through this journey you will discover more about how you were SHAPED for serving God.",
        "God did not design church ministry for a select few with seminary degrees. Every member of the church is a minister. This guided discovery is designed to help you understand your S.H.A.P.E. and how God may use you in ministry at Fellowship Dubai.",
        "As you begin, pray and expect God to grant you wisdom, discernment, and understanding. Remember that there are no wrong answers because this is your unique S.H.A.P.E. We are thrilled to help you find a fulfilling place to serve where you can use your gifts.",
        "At the end, download your S.H.A.P.E. Profile and use it as you prayerfully explore current opportunities to serve the Lord and His people.",
    ],
};
export const whatIsShapeSection = {
    id: "what-is-shape", title: "What is S.H.A.P.E.?", shortTitle: "What is SHAPE?", page: 3, eyebrow: "You were SHAPED to serve God",
    paragraphs: [
        "God formed every creature on this planet with a special area of expertise. Some animals run, some hop, some swim, some burrow, and some fly. Each has a particular role to play, based on the way it was shaped by God. The same is true with humans. Each of us was uniquely designed, or shaped, to do certain things.",
        "Before architects design any new building they first ask, ‘What will be its purpose? How will it be used?’ The intended function always determines the form of the building. Before God created you, He decided what role He wanted you to play on earth. He planned exactly how He wanted you to serve Him, and then He shaped you for those tasks. You are the way you are because you were made for a specific ministry.",
        "Not only did God shape you before your birth, He planned every day of your life to support His shaping process. David continues, ‘Every day of my life was recorded in your book. Every moment was laid out before a single day had passed.’ (Psalm 139:16) This means that nothing that happens in your life is insignificant. God uses all of it to mold you for your ministry to others and shape you for your service to Him.",
        "God never wastes anything. The Bible says you are ‘wonderfully complex.’ You are a combination of many different factors. By identifying and understanding these factors you can discover God’s will for your life. To help you remember five of these factors, we use the acrostic S.H.A.P.E.",
        "Ministry is using what God has uniquely given you to uniquely serve Him and the needs of others. These five factors help you determine where you can best serve Him with joy, fulfillment, and fruitfulness.",
        "God takes ordinary people and accomplishes extraordinary things through them!",
    ],
    bullets: ["Spiritual gifts", "Heart", "Abilities", "Personality", "Experience"],
};
export const gifts = [
    { id: "administration", label: "Administration", reference: "1 Cor. 12", alternateName: "Organization", description: "The ability to recognize the gifts of others and recruit them to a ministry. The ability to organize and manage people, resources, and time for effective ministry." },
    { id: "apostle", label: "Apostle", reference: "1 Cor. 12", description: "The ability to start new churches/ventures and oversee their development." },
    { id: "discernment", label: "Discernment", reference: "1 Cor. 12", description: "The ability to distinguish between the spirit of truth and the spirit of error. The ability to detect inconsistencies in another’s life and confront in love." },
    { id: "encouragement", label: "Encouragement", reference: "Rom. 12", alternateName: "Exhortation", description: "The ability to motivate God’s people to apply and act on biblical principles, especially when they are discouraged or wavering in their faith. The ability to bring out the best in others and challenge them to develop their potential." },
    { id: "evangelism", label: "Evangelism", reference: "Eph. 4", description: "The ability to communicate the Good News of Jesus Christ to unbelievers in a positive, non-threatening way. The ability to sense opportunities to share Christ and lead people to respond with faith." },
    { id: "faith", label: "Faith", reference: "1 Cor. 12", description: "The ability to trust God for what cannot be seen and to act on God’s promise, regardless of what the circumstances indicate. The willingness to risk failure in pursuit of a God-given vision, expecting God to handle the obstacles." },
    { id: "giving", label: "Giving", reference: "Rom. 12", description: "The ability to generously contribute material resources and/or money beyond the 10% tithe so that the Body may grow and be strengthened. The ability to manage money so it may be given to support the ministry of others." },
    { id: "healing", label: "Healing", reference: "1 Cor. 12", description: "The ability to pray, in faith, specifically for people who need physical, emotional, or spiritual healing and see God answer. The ability to sense when God is prompting you to pray this kind of prayer." },
    { id: "hospitality", label: "Hospitality", reference: "1 Peter 4:9-10", description: "The ability to make others, especially strangers, feel warmly welcomed, accepted, and comfortable in the church family. The ability to coordinate factors that promote fellowship." },
    { id: "leadership", label: "Leadership", reference: "Rom. 12", description: "The ability to clarify and communicate the purpose and direction (‘vision’) of a ministry in a way that attracts others to get involved. The ability to motivate others, by example, to work together in accomplishing a ministry goal." },
    { id: "mercy", label: "Mercy", reference: "Rom. 12", description: "The ability to manifest practical, compassionate, cheerful love toward suffering members of the Body of Christ." },
    { id: "miracles", label: "Miracles", reference: "1 Cor. 12", description: "The ability to pray, in faith, specifically for God’s supernatural intervention into an impossible situation and see God answer. The ability to sense when God is prompting you to pray this kind of prayer." },
    { id: "pastoring", label: "Pastoring", reference: "Eph. 4", alternateName: "Shepherding", description: "The ability to care for the spiritual needs of a group of believers and equip them for ministry. The ability to nurture a small group in spiritual growth and assume responsibility for their welfare." },
    { id: "praying-with-my-spirit", label: "Praying With My Spirit", reference: "1 Cor. 12", alternateName: "Tongues/Interpretation", description: "The ability to pray in a language understood only by God or one who is given the gift of interpretation at that time." },
    { id: "preaching", label: "Preaching", reference: "Rom. 12", alternateName: "Prophecy", description: "The ability to publicly communicate God’s Word in an inspired way that convinces unbelievers and both challenges and comforts believers. The ability to persuasively declare God’s will." },
    { id: "service", label: "Service", reference: "Rom. 12", description: "The ability to recognize unmet needs in the church family, and take the initiative to provide practical assistance quickly, cheerfully, and without a need for recognition." },
    { id: "teaching", label: "Teaching", reference: "Eph. 4", description: "The ability to educate God’s people by clearly explaining and applying the Bible in a way that causes them to learn. The ability to equip and train other believers for ministry." },
    { id: "wisdom", label: "Wisdom", reference: "1 Cor. 12", description: "The ability to understand God’s perspective on life situations and share those insights in a simple, understandable way. The ability to explain what to do and how to do it." },
];
export const spiritualGiftsSection = {
    id: "spiritual-gifts", title: "Spiritual Gifts", shortTitle: "Spiritual Gifts", page: 4, letter: "S", eyebrow: "S in SHAPE",
    scripture: { text: "The Holy Spirit displays God’s power through each of us as a means of helping the entire church.", reference: "1 Cor. 12:7" },
    paragraphs: [
        "Many of us have heard of spiritual gifts, but we are not quite sure we understand them. A spiritual gift is a special ability, given by the Holy Spirit to every believer at their conversion, to be used to minister to others and therefore build up the Body of Christ. Although spiritual gifts are given when the Holy Spirit enters new believers, their use and purpose need to be understood and developed as we grow spiritually. A spiritual gift is much like a muscle; the more you use it, the stronger it becomes.",
        "God desires you to know the spiritual gift(s) He has chosen for you. ‘Ask, and it shall be given to you; seek, and you shall find; knock, and it shall be opened to you. For everyone who asks receives, and he who seeks finds, and to him who knocks it shall be opened.’ (Matt. 7:7-8) Learning more about His gifts to us, and the many ways we can use those gifts within the church, is a life-long process.",
        "The Bible does not lock us into tight restrictions as to the number of spiritual gifts or even their definitions. The four major lists of gifts are found in Romans 12:3-8, 1 Cor. 12:1-11 and 27-31, Eph. 4:11-12, and 1 Peter 4:9-11. One person can have many gifts.",
        "As you read through the list, prayerfully consider if the biblical definition for each gift describes you. Remember, you can have more than one gift, but everyone has at least one.",
        "Example: Cindy has the gift of service and expresses this gift in Children’s Ministry. She secures textbooks, prepares handouts, and helps with registration, freeing the teacher to focus on using a speaking gift. The gift of service edifies the gifts of others. People with this gift often like to work behind the scenes.",
    ],
    bullets: ["Only believers have spiritual gifts. - 1 Cor. 2:14", "You cannot earn or work for a spiritual gift. - Eph. 4:7", "The Holy Spirit decides what gifts I get. - 1 Cor. 12:11", "I am to develop the gifts God gives me. - Rom. 11:29", "It is a sin to waste the gifts God gave me. - 1 Cor. 4:1-2; Matt. 25:14-30", "Using my gifts honors God and expands me. - John 15:8"],
};
export const giftResponseOptions = [o("likely", "I’m pretty sure I have this gift"), o("possible", "I may have this gift"), o("unlikely", "I don’t think I have this gift")];
export const heartRoles = [
    o("design-develop", "DESIGN/DEVELOP", "I love to make something out of nothing. I enjoy getting something started from scratch."),
    o("pioneer", "PIONEER", "I love to test and try out new concepts. I am not afraid to risk failure."),
    o("organize", "ORGANIZE", "I love to bring order out of chaos. I enjoy organizing something that is already started."),
    o("operate-maintain", "OPERATE/MAINTAIN", "I love to efficiently maintain something that is already organized."),
    o("serve-help", "SERVE/HELP", "I love to assist others in their responsibilities. I enjoy helping others succeed."),
    o("acquire-possess", "ACQUIRE/POSSESS", "I love to shop, collect, or obtain things. I enjoy getting the highest quality for the best price."),
    o("excel", "EXCEL", "I love to be the best and make my team the best. I enjoy setting and attaining the highest standard."),
    o("influence", "INFLUENCE", "I love to convert people to my way of thinking. I enjoy shaping the attitudes and behavior of others."),
    o("perform", "PERFORM", "I love to be on stage and receive the attention of others. I enjoy being in the limelight."),
    o("improve", "IMPROVE", "I love to make things better. I enjoy taking something that someone else has designed or started and improve it."),
    o("repair", "REPAIR", "I love to fix what is broken or change what is out of date."),
    o("lead", "LEAD/BE IN CHARGE", "I love to lead the way, oversee and supervise. I enjoy determining how things will be done."),
    o("persevere", "PERSEVERE", "I love to see things to completion. I enjoy persisting at something until it is finished."),
    o("follow-rules", "FOLLOW THE RULES", "I love to operate by policies and procedures. I enjoy meeting the expectations of an organization or boss."),
    o("prevail", "PREVAIL", "I love to fight for what is right and oppose what is wrong. I enjoy overcoming injustice."),
];
export const heartPeople = list(["Infants/Babies", "Toddlers", "Preschool Children", "Elementary Children", "Jr. High Students", "High School", "College/Career", "Young Marrieds", "Women", "Men", "Singles", "Single Parents", "Families", "Couples", "Older Adults 60+"]);
export const heartCauses = list(["Parenting", "Families/Marriage", "At-Risk Children", "Abuse/Violence", "Financial Management", "Divorce Recovery", "Disabilities and/or Support", "Deafness", "Blindness", "Law and/or Justice System", "Sanctity of Life", "Homelessness", "Drug and Alcohol Recovery", "Compulsive Behavior Recovery", "Illness and/or Injury", "Sexuality and/or Gender Issues", "Education", "Evangelism", "World Evangelization", "Fellowship", "Mobilizing People for Ministry", "Worship", "Policy and/or Politics", "Race", "Business and the Economy", "Relief Efforts", "Ethics", "Health and/or Fitness", "Science and/or Technology", "Environment", "International and Global Affairs", "Regional, State or Federal Issues", "Community/Neighborhood Issues"]);
export const heartSection = {
    id: "heart", title: "Heart", shortTitle: "Heart", page: 8, letter: "H", eyebrow: "H in SHAPE",
    scripture: { text: "Delight yourself in the Lord and he will give you the desires of your heart.", reference: "Psalm 37:4" },
    paragraphs: [
        "The Bible uses the term ‘heart’ to describe the bundle of desires, hopes, interests, ambitions, dreams, and affections you have. Your heart represents the source of all your motivations - what you love to do and what you care about most.",
        "The Bible says, ‘As a face is reflected in water, so the heart reflects the person.’ Your heart reveals the real you - what you truly are, not what others think you are or what circumstances force you to be. Your heart determines why you say the things you do, why you feel the way you do, and why you act the way you do.",
        "God has given each of us a unique emotional ‘heartbeat’ that races when we think about the subjects, activities, or circumstances that interest us. We instinctively care about some things and not about others. These are clues as to where you should be serving.",
        "Another word for heart is passion. Passions are desires or purposes that bring us joy. Some subjects capture your attention while others do not. These reveal the nature of your heart.",
    ],
    bullets: ["A passion for a role - what you like to do", "A passion for specific people - whom you like to help", "A passion for a cause - what you would like to see changed"],
};
export const heartQuestions = [
    { id: "heart-roles", type: "multi", prompt: "My Passion for a Role: I love to…", help: "Circle or check the roles you enjoy fulfilling.", page: 9, options: heartRoles, otherLabel: "Other role" },
    { id: "heart-people", type: "multi", prompt: "My Passion for People: I love to be or work with…", help: "Select the people on whom you feel you can make the greatest impact.", page: 10, options: heartPeople, otherLabel: "Other people group" },
    { id: "heart-causes", type: "multi", prompt: "My Passion for a Cause: I get excited about…", help: "Select the causes you feel led to champion.", page: 10, options: heartCauses, otherLabel: "Other cause" },
];
export const languageOptions = list(["American Sign", "Spanish", "French", "German", "Dutch", "Danish", "Romanian", "Portuguese", "Japanese", "Tagalog", "Hindi", "Farsi", "Hebrew", "Italian", "Swedish", "Greek", "Russian", "Other Slavic Languages", "Other European", "Chinese", "Korean", "Arabic", "Kurdish", "Other Middle Eastern", "Other Asian", "African Languages"]);
export const hobbyOptions = list(["Canning", "Woodworking", "Gardening", "Sewing", "Knitting/Crocheting", "Cooking", "Furniture Construction/Repair"]);
export const technicalOptions = list(["Recording Studio", "Audio/Technical Support", "Information Systems (Web)", "Set Up/Tear Down", "Media/Tape", "PowerPoint", "Video Artist/Technician", "Lighting Technician"]);
export const abilities = [
    o("entertaining", "Entertaining ability", "To perform, act, speak, sing"), o("recruiting", "Recruiting ability", "To enlist and motivate people to get involved"),
    o("interview", "Interview ability", "To discover what others are really like"), o("researching", "Researching ability", "To read, gather information, collect data"),
    o("artistic", "Artistic ability", "To create or design"), o("graphics", "Graphics ability", "To lay out, design, create visual displays or banners"),
    o("evaluating", "Evaluating ability", "To analyze data and draw conclusions"), o("planning", "Planning ability", "To strategize, design and organize programs and events"),
    o("managing", "Managing ability", "To supervise people to accomplish a task or event and coordinate the details"), o("counseling", "Counseling/Encouraging ability", "To listen, encourage and guide with sensitivity"),
    o("athletic", "Athletic ability", "To coach or participate in a sport"), o("teaching", "Teaching ability", "To explain, train, demonstrate, tutor"),
    o("writing", "Writing ability", "To write articles, letters, books"), { id: "linguistic", label: "Linguistic ability", description: "To speak, read, write, or translate in one or more languages", children: languageOptions },
    o("editing", "Editing ability", "To proofread or rewrite"), o("promoting", "Promoting ability", "To advertise or promote events and activities"),
    o("repairing", "Repairing ability", "To diagnose problems and fix, restore, or maintain equipment, furniture, facilities, or other items"), { id: "hobby", label: "Hobby related ability", description: "To work with your hands", children: hobbyOptions },
    o("feeding", "Feeding ability", "To create meals for large or small groups"), o("recall", "Recall ability", "To remember or recall names and faces"),
    o("mechanical", "Mechanical operating ability", "To operate equipment, tools or machinery"), { id: "technical", label: "Technical ability", description: "To operate/repair equipment", children: technicalOptions },
    o("resourceful", "Resourceful ability", "To search out and find inexpensive materials or resources needed"), o("counting", "Counting ability", "To work with numbers, data or money"),
    o("classifying", "Classifying ability", "To systematize and file books, data, records and materials so they can be retrieved easily"), o("public-relations", "Public Relations ability", "To handle complaints and unhappy people with care and maturity"),
    o("welcoming", "Welcoming ability", "To convey warmth, develop rapport, make others feel comfortable"), o("musical", "Musical ability", "To sing or play a musical instrument"),
    o("landscaping", "Landscaping ability", "To do gardening and work with plants"), o("decorating", "Decorating ability", "To beautify a setting for a special event"),
];
export const abilitiesSection = {
    id: "abilities", title: "Abilities", shortTitle: "Abilities", page: 11, letter: "A", eyebrow: "A in SHAPE",
    scripture: { text: "There are different abilities to perform service.", reference: "1 Cor. 12:6" },
    paragraphs: [
        "Your abilities are the natural talents with which you were born. All of our abilities come from God. The Bible says, ‘God has given each of us the ability to do certain things well.’ Since your natural abilities are from God, they are just as important as your spiritual gifts. The only difference is that you were given them at birth.",
        "One of the most common excuses people give for not serving is ‘I just don’t have any abilities to offer.’ You have dozens, probably hundreds, of untapped, unrecognized, and unused abilities that are lying dormant inside you. Many studies have revealed that the average person possesses from 500 to 700 different skills and abilities - far more than you realize.",
        "Every ability can be used for God’s glory. Paul said, ‘Whatever you do, do it all for the glory of God.’ The Bible is filled with examples of different abilities that God uses for his glory. God has a place in His church where your specialties can shine and you can make a difference. It is up to you to find that place.",
        "What I’m able to do, God wants me to do. You are the only person on earth who can use your abilities. No one else can play your role, because they do not have the unique shape that God has given you. To discover God’s will for your life, seriously examine what you are good at doing and what you are not good at doing.",
    ],
};
export const personalityPairs = [
    { id: "energy", prompt: "I tend to…", left: o("extroverted", "Be Extroverted", "I prefer interacting with many people and gain energy from being part of a variety of activities."), right: o("introverted", "Be Introverted", "I prefer interacting with only a few people and gain energy from quiet reflective time. I am a good listener.") },
    { id: "expression", prompt: "I tend to…", left: o("self-expressive", "Be Self-expressive", "I am more open and verbal about my thoughts and opinions. I enjoy sharing these with other people."), right: o("self-controlled", "Be Self-controlled", "I tend to keep my thoughts and opinions to myself.") },
    { id: "rhythm", prompt: "I tend to…", left: o("routine", "Prefer Routine", "I am more comfortable being involved in activities where I clearly know what is expected of me. I like closure and completion before starting something new."), right: o("variety", "Prefer Variety", "I am more fulfilled by tasks that change and maybe even have some surprises. Finishing one task before starting another is not crucial.") },
    { id: "team", prompt: "I tend to…", left: o("cooperative", "Be Cooperative", "As I work with others, I easily see their point of view. I like being part of a team effort."), right: o("competitive", "Be Competitive", "I like a sense of challenge. It increases my effort and helps me overcome the obstacles.") },
];
export const personalitySection = {
    id: "personality", title: "Personality", shortTitle: "Personality", page: 14, letter: "P", eyebrow: "P in SHAPE",
    paragraphs: [
        "Your personality will affect how and where you use your spiritual gifts and abilities. For instance, two people may have the same gift of evangelism, but if one is introverted and the other is extroverted, that gift will be expressed in different ways.",
        "Woodworkers know that it is easier to work with the grain rather than against it. In the same way, when you are forced to minister in a manner that is out of character for your temperament, it creates tension and discomfort, requires extra effort and energy, and produces less than the best results. This is why mimicking someone else’s ministry never works. God made you to be you. You can learn from others, but you must filter what you learn through your own shape.",
        "Like stained glass, our different personalities reflect God’s light in many colors and patterns. This blesses the family of God with depth and variety. When you minister in a manner consistent with the personality God gave you, you experience fulfillment, satisfaction, and fruitfulness.",
        "God has not used a cookie cutter to stamp out people in a process of uniformity. He loves variety. There is not a right or wrong temperament. We need opposites to balance the church. The personality traits are grouped in four couplets, each with two opposing tendencies.",
    ],
};
export const spiritualExperiences = list(["Baptized", "Community Outreach", "Cross Cultural Ministry", "Led Someone to Christ", "Member of a Small Group", "Member of Fellowship Dubai", "Regularly Give Back to God", "Regularly Pray", "Regularly Read the Bible", "Serving in a Ministry"]);
export const painfulExperiences = list(["Abortion", "Abuse", "Adoption", "Alcoholism", "Alzheimer’s/Dementia", "Annulment of Marriage", "Bankruptcy", "Cancer", "Chronic Pain", "Crisis Pregnancy", "Death/Grief", "Depression", "Divorce", "Drug Abuse/Addiction", "Eating Disorder", "Extended Court Proceedings", "Extended Depression", "Extended Job Loss", "Extended Physical Illness/Injury", "Handicapped/Disabled", "Homeless", "Military Wartime Service", "Miscarriage", "Orphaned", "Prison", "Recovery Program", "Remarried", "Separated and/or Restored Marriage", "Sexual Compulsion", "Suicide of Family Member", "Widowed"]);
export const educationalExperiences = list(["High School Diploma", "Associate of Arts Degree", "Bachelor Degree", "Masters Degree", "PHD/Doctoral Degree", "Specialized Advanced Training"]);
export const workExperiences = [
    o("agriculture", "Agriculture/Animal Husbandry"),
    { id: "automotive", label: "Automotive", children: list(["Management", "Manufacturing", "Mechanic/Maintenance", "Sales/Marketing"]) },
    { id: "business", label: "Business", children: list(["Administrative Support", "Advertising/Marketing", "Chemicals", "Consulting", "Construction/Building", "Financial Services", "Food/Restaurant Services", "Manufacturing", "Personal Care Products/Services", "Personnel/Human Relations", "Project/Area Management", "Product Receiving/Delivery", "Property Management", "Public Relations", "Purchasing", "Real Estate", "Recruiting/Head Hunting", "Sales/Retail", "Service/Customer Relations", "Sewing", "Small Business Owner"]) },
    { id: "creative-arts", label: "Creative Arts", children: list(["Architecture", "Advertising", "Computer Arts", "Fashion", "Fine Arts", "Graphic Design", "Illustration", "Industrial Design", "Interior Architecture & Design", "Motion Pictures & Television", "Photographer"]) },
    { id: "computer", label: "Computer", children: list(["Consulting", "Data Entry/Management", "Graphics", "Hardware", "Info Systems/Tech Support", "Printing", "Software", "Web Based"]) },
    o("engineering", "Engineering"),
    { id: "entertainment", label: "Entertainment", children: list(["Actor/Actress", "Banquets/Events", "Hotel Services", "Media (Radio, Video, Film, etc.)", "Resorts/Theme Parks"]) },
    { id: "education-field", label: "The Field of Education", children: list(["Christian/Religion", "Counseling", "History", "Languages", "Manual Arts/Vocational Skills", "Mathematics", "Music", "Physical Education/Coaching", "Preschool/Elementary Education", "Jr. High/HS Education", "Sciences", "Social Sciences", "Special Education", "Specialist", "Technology/Communication", "Training"]) },
    o("homemaker", "Homemaker"),
    { id: "medical", label: "Medical", children: list(["Administrative/Support Services", "Chiropractic", "Dental", "Dermatology", "General", "Geriatrics", "Internal", "Mental Health", "Nursing", "Nutrition", "Obstetrics/Gynecology", "Ophthalmology/Optometry", "Orthodontics", "Pathology", "Pediatrics", "Pharmacology", "Physical/Occupational Therapy", "Podiatry", "Sales/Services", "Speech Pathology", "Surgery"]) },
    { id: "military", label: "Military", children: list(["Air Force", "Army", "Coast Guard", "Marines", "Navy"]) },
    { id: "public-civil", label: "Public/Civil Services", children: list(["Fire protection", "Government Services", "Immigration Services", "Internal Revenue Service", "Investigation", "Law Enforcement/Security", "Policy/Permit", "Postal Services", "Social/Community Services", "Transportation"]) },
    o("retired", "Retired"), o("student", "Student"),
    { id: "tax-legal", label: "Tax/Legal/Court Services", children: list(["Attorney", "Certified Public Accountant", "Court Services", "Paralegal", "Prisoner Services (Bail, Parole)"]) },
    { id: "transportation", label: "Transportation", children: list(["Airline Attendant", "Airline Pilot", "Air Traffic Control", "Bus/Truck/Automobile Driver", "Railroad", "Travel Agency/Services"]) },
    { id: "utilities", label: "Utilities", children: list(["Cable Television", "Gas/Electric", "Phone", "Service/Sales/Mgmnt/Install", "Water"]) },
    { id: "vocational-ministry", label: "Vocational Ministry", children: list(["Administrator", "Director", "Pastor"]) },
];
export const ministryPeople = list(["College/Career", "Couples", "Elementary Students", "Family", "High School Students", "Infants/Babies", "Junior High Students", "Men", "Preschool Students", "Senior Adults", "Single Adults", "Young Marrieds", "Women"]);
export const ministryRoles = list(["Administrative Support", "Baking/Cooking", "Baptism", "Bible Teacher", "Camp Counselor", "Career Missions/World Impact", "Children’s Ministry", "Choir", "Church Life (Weddings, Funerals, Anniversaries, etc.)", "Cleaning/Maintenance/Repair", "Communications/Tech/Graphics", "Communion", "Deaf Ministry", "Disciple/Mentor", "Discussion/Table Leader", "Drama", "Food Preparation/Café", "Facilities Oversight", "Financial Counselor", "Greeter/Usher", "Hosting", "Hospital Care", "In Home Care/Senior Ministry", "Lay Counselor", "Library", "Media/Tape", "Musician", "Prayer Team", "Prison Ministry", "Program/Event Coordinator", "Retreat Planning", "Security", "Set Up/Tear Down", "Small Group Leader", "Speaker/Trainer", "Technical/AV Support", "Traffic", "Van/Bus/Truck Driver", "Writing", "Worship Leader"]);
export const experiencesSection = {
    id: "experiences", title: "Experiences", shortTitle: "Experiences", page: 16, letter: "E", eyebrow: "E in SHAPE",
    scripture: { text: "And we know that God causes all things to work together for good to those who love God, to those who are called according to His purpose.", reference: "Romans 8:28" },
    paragraphs: [
        "You have been shaped by your experiences in life, most of which were beyond your control. God allowed them for His purpose of molding you. In determining your shape for serving God, examine at least five kinds of experiences from your past.",
        "It is often painful experiences that God uses to prepare us for ministry. God never wastes a hurt. Your greatest ministry may come out of your greatest hurt. Who could better understand and support someone in a particular trial than a person who has walked through it and found comfort?",
        "God allows you to go through painful experiences to equip you for ministry to others. ‘He comforts us in all our troubles so that we can comfort others. When others are troubled, we will be able to give them the same comfort God has given us.’ (2 Cor. 1:4)",
        "The experiences you have resented or regretted most - the ones you may have wanted to hide and forget - can become experiences God uses to help others.",
    ],
    bullets: ["Ministry experiences: How have you served God in the past?", "Work experiences: What jobs have you been most effective in and enjoyed most?", "Educational experiences: What were your favorite subjects in school?", "Spiritual experiences: What have been your most meaningful times with God?", "Painful experiences: From what problems, hurts, thorns, and trials have you learned?"],
};
export const experienceQuestions = [
    { id: "spiritual-experiences", type: "multi", prompt: "Select Your Spiritual Experiences", page: 17, options: spiritualExperiences, otherLabel: "Other spiritual experience" },
    { id: "painful-experiences", type: "multi", prompt: "Painful Experiences (I can relate to someone who is/or has gone through…)", help: "Only share what you are comfortable sharing. Your answers are saved in this browser and may appear in the profile you download, copy, email, print, or share.", page: 17, options: painfulExperiences, otherLabel: "Other painful experience" },
    { id: "educational-experiences", type: "multi", prompt: "Select Your Educational Experiences", page: 17, options: educationalExperiences, otherLabel: "Other educational experience" },
    { id: "work-experiences", type: "multi", prompt: "Select Your Work Experiences", page: [17, 18, 19], options: workExperiences, otherLabel: "Other work experience" },
    { id: "ministry-people", type: "multi", prompt: "I have worked with… (Age/Life stage)", page: 19, options: ministryPeople, otherLabel: "Other age or life stage", maxSelections: 3 },
    { id: "ministry-roles", type: "multi", prompt: "Ministry Roles/Functions", page: [19, 20], options: ministryRoles, otherLabel: "Other ministry role/function", maxSelections: 3 },
];
export const availabilitySection = {
    id: "availability", title: "Determining My Availability", shortTitle: "Availability", page: 21, eyebrow: "It does not take great ability; it takes great availability for God to make an impact with your life!",
    scripture: { text: "Whoever serves Me must follow Me; and where I am, My servant also will be. My Father will honor the one who serves Me.", reference: "John 12:26 NIV" },
    paragraphs: [
        "You have examined your spiritual gifts, your heart’s desires, your natural abilities, your personality bent and your life experiences. Now you need to consider how you are going to use the way God has shaped you to serve within the church.",
        "Real servants make themselves available to serve. They want to be ready to jump into service when called on. Much like a soldier, a servant must stand by for duty: ‘No soldier in active service entangles himself in the affairs of everyday life, so that he may please the one who enlisted him.’ (2 Timothy 2:4 NASB)",
    ],
    questions: [
        { id: "service-priority", type: "text", prompt: "Are you making service a priority?", page: 21, multiline: true, placeholder: "Reflect briefly…" },
        { id: "hours", type: "single", prompt: "Please select the amount of time you could serve per week:", page: 21, options: list(["1-2 hours", "3-5 hours", "6+ hours"]) },
        { id: "timing", type: "multi", prompt: "Please select your best time during the week:", page: 21, options: list(["Weekday", "Weeknight", "Weekend"]) },
    ],
};
export const startWays = [
    { letter: "S", title: "Study", text: "We have found these books to be helpful in the development of one’s SHAPE.", bullets: ["19 Gifts of the Spirit, by Leslie B. Flynn", "Your Spiritual Gifts Can Help Your Church Grow, by C. Peter Wagner", "Half-Time, by Bob Buford", "Power of Uniqueness, by Arthur F. Miller Jr. with William Hendricks"] },
    { letter: "T", title: "Trial and error", text: "Experiment with different areas of service. It is easier to discover your gift through ministry than to discover your ministry through your gift. Try a few ministries, notice what fits and what does not, and stay committed to what fits your SHAPE." },
    { letter: "A", title: "Analyze", text: "Take time to pray, journal and reflect on the way God has SHAPED you and how every aspect of your SHAPE interrelates. God often uses your entire SHAPE to be expressed in an area of service." },
    { letter: "R", title: "Request input from others", text: "Ask others what gifts they see in you." },
    { letter: "T", title: "Take training", text: "Explore classes and resources at Fellowship Dubai that can enhance your ministry." },
];
export const journeySteps = [
    { id: "welcome", title: "Welcome", pages: [1] }, { id: "contents", title: "Journey Map", pages: [2] }, { id: "what-is-shape", title: "What is SHAPE?", pages: [3] },
    { id: "gifts-teaching", title: "Spiritual Gifts", pages: [4] }, { id: "gifts-assessment", title: "Unwrapping My Gifts", pages: [5, 6, 7] },
    { id: "heart-teaching", title: "Heart", pages: [8] }, { id: "heart-roles", title: "Passion for a Role", pages: [9] }, { id: "heart-people-causes", title: "People & Causes", pages: [10] },
    { id: "abilities-teaching", title: "Abilities", pages: [11] }, { id: "abilities-assessment", title: "Applying My Abilities", pages: [12, 13] },
    { id: "personality-teaching", title: "Personality", pages: [14] }, { id: "personality-assessment", title: "Plugging In My Personality", pages: [15] },
    { id: "experiences-teaching", title: "Experiences", pages: [16] }, { id: "experiences-personal", title: "Personal Experiences", pages: [17] },
    { id: "experiences-work", title: "Work Experiences", pages: [17, 18, 19] }, { id: "experiences-ministry", title: "Ministry Experiences", pages: [19, 20] },
    { id: "availability", title: "Availability", pages: [21] }, { id: "start", title: "S.T.A.R.T.", pages: [23] },
    { id: "profile", title: "My SHAPE Profile", pages: [] },
];
